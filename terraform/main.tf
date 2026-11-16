# Recursos da Sprint 1 (o provider aws está em providers.tf): a EC2 solta, trocada pelo Auto Scaling
# na feat/infra-computacao, e o RDS, levado às subnets privadas na feat/infra-dados-seguranca.
# A senha da linha 23 não muda (decisão de 13/11); o ssm.tf passa o mesmo valor ao SSM por referência.

# Criação de uma instância EC2 simulada para hospedar o Docker da Barbearia
resource "aws_instance" "barbershop_server" {
  ami           = "ami-0c7217cdde317cfec" # Exemplo Amazon Linux 2023
  instance_type = "t3.micro"

  tags = {
    Name = "BarberShop-Cloud-Instance"
  }
}

# Banco de dados gerenciado RDS MySQL na Nuvem
resource "aws_db_instance" "barbershop_db" {
  allocated_storage   = 20
  engine              = "mysql"
  engine_version      = "8.4"
  instance_class      = "db.t3.micro"
  db_name             = "barbershop"
  username            = "admincloud"
  password            = "SenhaSeguraNuvem2026!"
  skip_final_snapshot = false

  # Os atributos abaixo entraram na feat/infra-dados-seguranca. Ficam depois da senha
  # para ela continuar na linha 23, que o docs/nuvem/TERRAFORM.md cita.

  # Nome do banco na AWS (barberflow-<ambiente>-db). Os log groups do RDS (logs.tf), os
  # alarmes da feat/observabilidade e os comandos do docs/nuvem/BACKUP.md usam este nome.
  identifier = local.db_identifier
  port       = 3306

  # Rede: subnets privadas, sem rota para a internet, e só o security group do app entra (sg.tf).
  db_subnet_group_name   = aws_db_subnet_group.db.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false

  # Multi-AZ: uma réplica de espera em outra zona, que assume sozinha (prod).
  multi_az = var.db_multi_az

  # Disco gp3 criptografado com a chave padrão do RDS (aws/rds).
  storage_type      = "gp3"
  storage_encrypted = true

  # TLS obrigatório e log de consultas lentas (parameter group abaixo). Os logs de erro e de
  # consultas lentas vão para o CloudWatch, nos grupos criados no logs.tf.
  parameter_group_name            = aws_db_parameter_group.mysql.name
  enabled_cloudwatch_logs_exports = ["error", "slowquery"]

  # Backup automático de 7 dias (restore de qualquer minuto do período, docs/nuvem/BACKUP.md)
  # e snapshot final quando o banco é apagado. Horários em UTC: backup das 03:00 às 04:00 e
  # manutenção de domingo das 04:30 às 05:30, em Brasília, com a barbearia fechada.
  backup_retention_period   = 7
  backup_window             = "06:00-07:00"
  maintenance_window        = "sun:07:30-sun:08:30"
  final_snapshot_identifier = "${local.db_identifier}-final"
  copy_tags_to_snapshot     = true

  # Com true (prod), nem o terraform destroy apaga o banco.
  deletion_protection = var.db_deletion_protection

  # Correções da 8.4 (8.4.x) aplicadas pela AWS na janela de manutenção.
  auto_minor_version_upgrade = true

  tags = {
    Name = local.db_identifier
  }

  # Os log groups precisam existir antes do banco: senão o RDS cria os dele, sem retenção.
  depends_on = [aws_cloudwatch_log_group.rds]
}

locals {
  db_identifier = "${local.name_prefix}-db"
}

resource "aws_db_subnet_group" "db" {
  name        = "${local.name_prefix}-db"
  description = "Subnets privadas do banco do BarberFlow"
  subnet_ids  = aws_subnet.private[*].id

  tags = {
    Name = "${local.name_prefix}-db"
  }
}

# MySQL 8.4 com:
#   require_secure_transport = 1  recusa conexão sem TLS (a app usa o MYSQL_ATTR_SSL_CA do SSM);
#   slow_query_log = 1 e long_query_time = 1  registram as consultas de mais de 1 segundo;
#   log_output = FILE  grava o log em arquivo, o único jeito de ele chegar ao CloudWatch
#                      (o padrão do RDS é TABLE, uma tabela dentro do próprio banco).
resource "aws_db_parameter_group" "mysql" {
  name        = "${local.name_prefix}-mysql84"
  family      = "mysql8.4"
  description = "MySQL 8.4 do BarberFlow: TLS exigido e log de consultas lentas"

  parameter {
    name  = "require_secure_transport"
    value = "1"
  }

  parameter {
    name  = "slow_query_log"
    value = "1"
  }

  parameter {
    name  = "long_query_time"
    value = "1"
  }

  parameter {
    name  = "log_output"
    value = "FILE"
  }

  tags = {
    Name = "${local.name_prefix}-mysql84"
  }
}