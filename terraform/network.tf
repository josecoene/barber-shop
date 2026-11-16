# Rede do BarberFlow: uma VPC com uma subnet pública e uma privada em cada zona.
# Públicas: ALB e instâncias do Auto Scaling (feat/infra-computacao).
# Privadas: banco e cache (feat/infra-dados-seguranca), sem rota para a internet.
# Não há NAT Gateway: as instâncias ficam nas públicas, com IP público dado pelo launch
# template, e os security groups deixam entrar só o tráfego que vem do ALB.

data "aws_availability_zones" "available" {
  state = "available"

  # Só zonas de disponibilidade. Sem este filtro, a lista traz também as Local Zones e as
  # Wavelength Zones em que a conta entrou (ex.: us-east-1-bos-1a), e elas vêm antes na ordem
  # por nome: as subnets iriam para lá, onde RDS, cache e ALB podem não existir.
  filter {
    name   = "zone-type"
    values = ["availability-zone"]
  }

  lifecycle {
    postcondition {
      condition     = length(self.names) >= var.az_count
      error_message = "A região escolhida não tem zonas disponíveis suficientes para o az_count."
    }
  }
}

locals {
  azs = slice(data.aws_availability_zones.available.names, 0, var.az_count)

  # Com a VPC 10.20.0.0/16: públicas em 10.20.0.0/24, 10.20.1.0/24...
  # e privadas em 10.20.10.0/24, 10.20.11.0/24...
  public_subnet_cidrs  = [for i in range(var.az_count) : cidrsubnet(var.vpc_cidr, 8, i)]
  private_subnet_cidrs = [for i in range(var.az_count) : cidrsubnet(var.vpc_cidr, 8, i + 10)]
}

resource "aws_vpc" "main" {
  cidr_block = var.vpc_cidr

  # Os dois ligados: o endpoint do RDS e do cache são nomes de DNS resolvidos dentro da VPC.
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "${local.name_prefix}-vpc"
  }
}

resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "${local.name_prefix}-igw"
  }
}

resource "aws_subnet" "public" {
  count = var.az_count

  vpc_id            = aws_vpc.main.id
  cidr_block        = local.public_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  # Nada recebe IP público só por estar aqui: quem dá o IP às instâncias é o launch template.
  map_public_ip_on_launch = false

  tags = {
    Name = "${local.name_prefix}-public-${local.azs[count.index]}"
    Tier = "public"
  }
}

resource "aws_subnet" "private" {
  count = var.az_count

  vpc_id                  = aws_vpc.main.id
  cidr_block              = local.private_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = false

  tags = {
    Name = "${local.name_prefix}-private-${local.azs[count.index]}"
    Tier = "private"
  }
}

# As duas tabelas declaram as rotas aqui dentro (route). Rota nova entra num bloco route,
# nunca num aws_route avulso: a documentação do provider não permite misturar os dois na
# mesma tabela, e a rota avulsa sumiria e voltaria a cada apply. O teste da rede falha se achar um.
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id
  }

  tags = {
    Name = "${local.name_prefix}-public"
  }
}

# Tabela das privadas: só a rota local da VPC, que a AWS cria sozinha.
# route = [] faz o Terraform apagar qualquer rota que alguém acrescente pelo console.
# Um aws_route apontando para esta tabela daria a banco e cache um caminho para a internet.
resource "aws_route_table" "private" {
  vpc_id = aws_vpc.main.id
  route  = []

  tags = {
    Name = "${local.name_prefix}-private"
  }
}

resource "aws_route_table_association" "public" {
  count = var.az_count

  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

resource "aws_route_table_association" "private" {
  count = var.az_count

  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private.id
}

# Toda VPC nasce com um security group padrão que libera o tráfego entre os seus membros e
# toda a saída. Aqui ele fica sem regra nenhuma: cada recurso usa o security group próprio,
# criado na feat/infra-dados-seguranca, e o que cair no padrão por engano fica isolado.
resource "aws_default_security_group" "default" {
  vpc_id = aws_vpc.main.id

  ingress = []
  egress  = []

  tags = {
    Name = "${local.name_prefix}-default-sem-regras"
  }
}
