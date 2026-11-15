# Security groups do BarberFlow: um por camada, e cada camada só aceita a da frente.
#   alb    recebe 80 e 443 da internet (IPv4 e IPv6) e só fala com o app na 80
#   app    recebe 80 só do ALB; sai na 443 e na porta do SMTP para a internet (não há NAT
#          Gateway nem VPC endpoints), na 3306 só para o banco e na 6379 só para o cache
#   db     recebe 3306 só do app; sem saída
#   cache  recebe 6379 só do app; sem saída
# Nenhuma porta 22: o acesso às instâncias é pelo Session Manager (iam.tf).
#
# As regras são recursos aws_vpc_security_group_ingress_rule e _egress_rule (o estilo atual
# do provider), cada uma apontando direto para o seu security group, para o trivy ligar a
# regra ao grupo. Nunca escreva regra dentro do aws_security_group (bloco ingress { } ou
# atributo ingress = [{ ... }], e o mesmo para egress) nem use o aws_security_group_rule
# antigo. O tests/dados_seguranca.tftest.hcl conta os security groups e as regras da pasta:
# security group ou regra nova entra também nas listas do teste.
#
# As descrições ficam sem acento: a AWS só aceita letras sem acento, números, espaço e
# ._-:/()#,@[]+=&;{}!$* na descrição do security group e da regra.

resource "aws_security_group" "alb" {
  name        = "${local.name_prefix}-alb"
  description = "ALB do BarberFlow: HTTP e HTTPS da internet"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name_prefix}-alb"
  }
}

resource "aws_security_group" "app" {
  name        = "${local.name_prefix}-app"
  description = "Instancias do app do BarberFlow: HTTP so do ALB"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name_prefix}-app"
  }
}

resource "aws_security_group" "db" {
  name        = "${local.name_prefix}-db"
  description = "RDS MySQL do BarberFlow: 3306 so do app"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name_prefix}-db"
  }
}

resource "aws_security_group" "cache" {
  name        = "${local.name_prefix}-cache"
  description = "ElastiCache Valkey do BarberFlow: 6379 so do app"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name_prefix}-cache"
  }
}

# ---------------------------------------------------------------------------
# Entradas
# ---------------------------------------------------------------------------

# Só o ALB recebe da internet: 80 e 443, em IPv4 e IPv6.
locals {
  alb_ingress_from_internet = {
    http_ipv4  = { port = 80, cidr_ipv4 = "0.0.0.0/0", description = "HTTP da internet (IPv4)" }
    http_ipv6  = { port = 80, cidr_ipv6 = "::/0", description = "HTTP da internet (IPv6)" }
    https_ipv4 = { port = 443, cidr_ipv4 = "0.0.0.0/0", description = "HTTPS da internet (IPv4)" }
    https_ipv6 = { port = 443, cidr_ipv6 = "::/0", description = "HTTPS da internet (IPv6)" }
  }
}

resource "aws_vpc_security_group_ingress_rule" "alb_from_internet" {
  for_each = local.alb_ingress_from_internet

  security_group_id = aws_security_group.alb.id
  description       = each.value.description
  ip_protocol       = "tcp"
  from_port         = each.value.port
  to_port           = each.value.port
  cidr_ipv4         = try(each.value.cidr_ipv4, null)
  cidr_ipv6         = try(each.value.cidr_ipv6, null)

  tags = {
    Name = "${local.name_prefix}-alb-${replace(each.key, "_", "-")}"
  }
}

resource "aws_vpc_security_group_ingress_rule" "app_from_alb" {
  security_group_id            = aws_security_group.app.id
  description                  = "HTTP vindo so do ALB"
  ip_protocol                  = "tcp"
  from_port                    = 80
  to_port                      = 80
  referenced_security_group_id = aws_security_group.alb.id

  tags = {
    Name = "${local.name_prefix}-app-from-alb"
  }
}

resource "aws_vpc_security_group_ingress_rule" "db_from_app" {
  security_group_id            = aws_security_group.db.id
  description                  = "MySQL vindo so do app"
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = aws_security_group.app.id

  tags = {
    Name = "${local.name_prefix}-db-from-app"
  }
}

resource "aws_vpc_security_group_ingress_rule" "cache_from_app" {
  security_group_id            = aws_security_group.cache.id
  description                  = "Valkey vindo so do app"
  ip_protocol                  = "tcp"
  from_port                    = 6379
  to_port                      = 6379
  referenced_security_group_id = aws_security_group.app.id

  tags = {
    Name = "${local.name_prefix}-cache-from-app"
  }
}

# ---------------------------------------------------------------------------
# Saídas. db e cache não têm nenhuma: respondem às conexões que receberam (o security
# group guarda o estado da conexão) e não abrem conexão para fora.
# ---------------------------------------------------------------------------

# O ALB repassa as requisições e faz o health check no app, na porta 80.
resource "aws_vpc_security_group_egress_rule" "alb_to_app" {
  security_group_id            = aws_security_group.alb.id
  description                  = "HTTP e health check para o app"
  ip_protocol                  = "tcp"
  from_port                    = 80
  to_port                      = 80
  referenced_security_group_id = aws_security_group.app.id

  tags = {
    Name = "${local.name_prefix}-alb-to-app"
  }
}

# O app sai só pelas portas de que precisa. Sem NAT Gateway nem VPC endpoints, a instância
# fala com a AWS e com a internet pelo IP público:
#   443   HTTPS: ECR (e as camadas das imagens, que ficam no S3), SSM e Session Manager,
#         CloudWatch Logs, repositórios do dnf e o plugin compose no GitHub (user_data da
#         feat/infra-computacao);
#   SMTP  a porta do provedor de e-mail (variável mail_port, a mesma do MAIL_PORT do ssm.tf).
# DNS da VPC, metadados da instância (IMDS) e Time Sync da AWS não passam por security group.
# Precisa de outra porta? Acrescente uma regra aqui e no teste, em vez de liberar tudo.
locals {
  app_egress_to_internet = {
    https = { port = 443, description = "HTTPS para ECR, S3, SSM, CloudWatch Logs, dnf e GitHub (sem NAT nem VPC endpoints)" }
    smtp  = { port = var.mail_port, description = "SMTP do provedor de e-mail" }
  }
}

resource "aws_vpc_security_group_egress_rule" "app_to_internet" {
  for_each = local.app_egress_to_internet

  security_group_id = aws_security_group.app.id
  description       = each.value.description
  ip_protocol       = "tcp"
  from_port         = each.value.port
  to_port           = each.value.port
  cidr_ipv4         = "0.0.0.0/0"

  tags = {
    Name = "${local.name_prefix}-app-to-internet-${each.key}"
  }
}

# Banco e cache ficam na mesma VPC: a saída vai só para o security group de cada um.
resource "aws_vpc_security_group_egress_rule" "app_to_db" {
  security_group_id            = aws_security_group.app.id
  description                  = "MySQL so para o banco"
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = aws_security_group.db.id

  tags = {
    Name = "${local.name_prefix}-app-to-db"
  }
}

resource "aws_vpc_security_group_egress_rule" "app_to_cache" {
  security_group_id            = aws_security_group.app.id
  description                  = "Valkey so para o cache"
  ip_protocol                  = "tcp"
  from_port                    = 6379
  to_port                      = 6379
  referenced_security_group_id = aws_security_group.cache.id

  tags = {
    Name = "${local.name_prefix}-app-to-cache"
  }
}
