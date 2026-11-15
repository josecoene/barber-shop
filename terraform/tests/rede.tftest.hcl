# Testes da rede com o provider AWS simulado (mock): rodam sem conta e sem credencial.
# Na pasta terraform/: terraform init -backend=false e depois terraform test.
# O mock devolve o que a configuração pede e inventa só os valores calculados pela AWS
# (IDs, por exemplo). Por isso os testes conferem a configuração, não o comportamento da AWS.
# As rotas lidas aqui são só as inline das tabelas (route). Um aws_route avulso não aparece
# nelas; por isso um assert lê o texto dos .tf da pasta e falha se achar um.

mock_provider "aws" {
  mock_data "aws_availability_zones" {
    defaults = {
      names = ["us-east-1a", "us-east-1b", "us-east-1c"]
    }
  }

  # Recursos da feat/infra-computacao cujo ID ou ARN outro recurso recebe: o provider confere o
  # formato (ARN do ALB e do target group, lt- no launch template), e o texto que o mock inventa
  # não passaria.
  mock_resource "aws_lb" {
    defaults = {
      arn = "arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/barberflow-homolog-alb/0123456789abcdef"
    }
  }

  mock_resource "aws_lb_target_group" {
    defaults = {
      arn = "arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/barberflow-homolog-app/0123456789abcdef"
    }
  }

  mock_resource "aws_launch_template" {
    defaults = {
      id = "lt-0123456789abcdef0"
    }
  }

  # Tópico dos alarmes da feat/observabilidade: as ações dos alarmes do CloudWatch precisam de um
  # ARN no formato certo.
  mock_resource "aws_sns_topic" {
    defaults = {
      arn = "arn:aws:sns:us-east-1:123456789012:barberflow-homolog-alarms"
    }
  }
}

variables {
  environment = "homolog"
}

run "homolog_network" {
  command = apply

  assert {
    condition     = length(aws_subnet.public) == 2 && length(aws_subnet.private) == 2
    error_message = "A rede deve ter 2 subnets públicas e 2 privadas."
  }

  assert {
    condition     = length(distinct(aws_subnet.public[*].availability_zone)) == 2
    error_message = "As 2 subnets públicas devem ficar em zonas diferentes."
  }

  assert {
    condition     = length(distinct(aws_subnet.private[*].availability_zone)) == 2
    error_message = "As 2 subnets privadas devem ficar em zonas diferentes."
  }

  assert {
    condition     = output.azs == tolist(["us-east-1a", "us-east-1b"])
    error_message = "A rede deve usar as 2 primeiras zonas disponíveis da região."
  }

  # O mock devolve as zonas do mock_data com ou sem filtro; este assert garante que o filtro
  # continua lá, para uma Local Zone não entrar na frente das zonas de disponibilidade.
  assert {
    condition     = anytrue([for f in data.aws_availability_zones.available.filter : f.name == "zone-type" && toset(f.values) == toset(["availability-zone"])])
    error_message = "A lista de zonas deve pedir só zonas de disponibilidade (filtro zone-type = availability-zone), sem Local Zone nem Wavelength Zone."
  }

  assert {
    condition     = aws_subnet.public[*].cidr_block == ["10.20.0.0/24", "10.20.1.0/24"]
    error_message = "As subnets públicas devem ser 10.20.0.0/24 e 10.20.1.0/24."
  }

  assert {
    condition     = aws_subnet.private[*].cidr_block == ["10.20.10.0/24", "10.20.11.0/24"]
    error_message = "As subnets privadas devem ser 10.20.10.0/24 e 10.20.11.0/24."
  }

  assert {
    condition     = alltrue([for s in concat(aws_subnet.public, aws_subnet.private) : s.map_public_ip_on_launch == false])
    error_message = "Nenhuma subnet pode dar IP público automático."
  }

  assert {
    condition     = aws_vpc.main.enable_dns_support && aws_vpc.main.enable_dns_hostnames
    error_message = "A VPC precisa do DNS ligado (enable_dns_support e enable_dns_hostnames)."
  }

  assert {
    condition     = length([for r in aws_route_table.public.route : r if r.cidr_block == "0.0.0.0/0" && r.gateway_id == aws_internet_gateway.main.id]) == 1
    error_message = "A tabela de rotas pública deve mandar 0.0.0.0/0 para o internet gateway."
  }

  assert {
    condition     = length(aws_route_table.private.route) == 0
    error_message = "A tabela de rotas privada deve ter só a rota local: nada para o internet gateway nem para a internet."
  }

  # Lê o texto dos .tf desta pasta: um aws_route avulso não aparece em aws_route_table.private.route.
  # Exigir o network.tf na lista garante que a leitura achou os arquivos e não passou à toa.
  assert {
    condition     = contains(fileset(path.module, "*.tf"), "network.tf") && length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^\\s*resource\\s+\"aws_route\"", file("${path.module}/${f}"))])) == 0
    error_message = "Nenhum aws_route avulso: as rotas ficam nos blocos route das duas tabelas (network.tf). Na privada, ele daria a banco e cache um caminho para a internet sem os outros asserts verem. O assert também falha se não achar o network.tf na pasta."
  }

  assert {
    condition     = toset(aws_route_table_association.public[*].subnet_id) == toset(aws_subnet.public[*].id) && alltrue([for a in aws_route_table_association.public : a.route_table_id == aws_route_table.public.id])
    error_message = "Cada subnet pública deve usar a tabela de rotas pública."
  }

  assert {
    condition     = toset(aws_route_table_association.private[*].subnet_id) == toset(aws_subnet.private[*].id) && alltrue([for a in aws_route_table_association.private : a.route_table_id == aws_route_table.private.id])
    error_message = "Cada subnet privada deve usar a tabela de rotas privada."
  }

  assert {
    condition     = aws_default_security_group.default.vpc_id == aws_vpc.main.id && length(aws_default_security_group.default.ingress) == 0 && length(aws_default_security_group.default.egress) == 0
    error_message = "O security group padrão da VPC não pode ter regra nenhuma."
  }

  assert {
    condition     = aws_vpc.main.tags["Name"] == "barberflow-homolog-vpc"
    error_message = "O nome da VPC deve seguir o prefixo projeto-ambiente."
  }

  assert {
    condition     = local.tags == { Project = "barberflow", Environment = "homolog", ManagedBy = "Terraform", Course = "Computacao em Nuvem" }
    error_message = "As tags padrão devem ser Project, Environment, ManagedBy e Course."
  }
}

run "prod_network_three_azs" {
  command = apply

  variables {
    environment = "prod"
    vpc_cidr    = "10.30.0.0/16"
    az_count    = 3
  }

  assert {
    condition     = length(distinct(aws_subnet.public[*].availability_zone)) == 3 && length(distinct(aws_subnet.private[*].availability_zone)) == 3
    error_message = "Com az_count = 3, as públicas e as privadas devem ficar em 3 zonas diferentes."
  }

  assert {
    condition     = aws_subnet.private[*].cidr_block == ["10.30.10.0/24", "10.30.11.0/24", "10.30.12.0/24"]
    error_message = "As subnets privadas devem seguir a faixa da VPC da prod."
  }

  assert {
    condition     = length(aws_route_table.private.route) == 0
    error_message = "A tabela de rotas privada da prod também não pode sair para a internet."
  }
}

# A menor VPC aceita (/19) ainda dá subnets /27, o mínimo que o ALB aceita.
run "accepts_smallest_vpc" {
  command = apply

  variables {
    vpc_cidr = "10.20.0.0/19"
  }

  assert {
    condition     = aws_subnet.public[*].cidr_block == ["10.20.0.0/27", "10.20.0.32/27"] && aws_subnet.private[*].cidr_block == ["10.20.1.64/27", "10.20.1.96/27"]
    error_message = "Com a VPC /19, as subnets devem ser /27: públicas em 10.20.0.0/27 e 10.20.0.32/27, privadas em 10.20.1.64/27 e 10.20.1.96/27."
  }
}

run "rejects_unknown_environment" {
  command = plan

  variables {
    environment = "dev"
  }

  expect_failures = [var.environment]
}

run "rejects_single_az" {
  command = plan

  variables {
    az_count = 1
  }

  expect_failures = [var.az_count]
}

run "rejects_misaligned_vpc_cidr" {
  command = plan

  variables {
    vpc_cidr = "10.20.5.0/16"
  }

  expect_failures = [var.vpc_cidr]
}

# Uma VPC /20 daria subnets /28, menores que o mínimo do ALB.
run "rejects_vpc_too_small" {
  command = plan

  variables {
    vpc_cidr = "10.20.0.0/20"
  }

  expect_failures = [var.vpc_cidr]
}

run "rejects_more_azs_than_region_has" {
  command = plan

  variables {
    az_count = 4
  }

  expect_failures = [data.aws_availability_zones.available]
}
