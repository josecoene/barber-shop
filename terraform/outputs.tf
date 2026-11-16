# Saídas da rede e, no fim, dos dados e da segurança. Dentro desta pasta, as próximas
# branches usam os recursos direto (aws_vpc.main, aws_subnet.public, aws_subnet.private);
# as saídas servem para conferir o que foi criado e para scripts e pipelines lerem com
# terraform output.

output "vpc_id" {
  description = "ID da VPC."
  value       = aws_vpc.main.id
}

output "public_subnet_ids" {
  description = "IDs das subnets públicas (ALB e instâncias), na mesma ordem das zonas em azs."
  value       = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  description = "IDs das subnets privadas (banco e cache), na mesma ordem das zonas em azs."
  value       = aws_subnet.private[*].id
}

output "azs" {
  description = "Zonas de disponibilidade usadas."
  value       = local.azs
}

# Saídas dos dados e da segurança (feat/infra-dados-seguranca). A feat/infra-computacao usa
# os recursos direto (aws_security_group.alb, aws_iam_instance_profile.ec2,
# aws_ecr_repository.image etc.). Nenhuma saída é segredo: a senha do banco só vai para o
# parâmetro DB_PASSWORD do SSM.

output "alb_security_group_id" {
  description = "Security group do ALB (80 e 443 da internet)."
  value       = aws_security_group.alb.id
}

output "app_security_group_id" {
  description = "Security group das instâncias do app (80 só do ALB)."
  value       = aws_security_group.app.id
}

output "db_security_group_id" {
  description = "Security group do RDS (3306 só do app). Vai no restore do docs/nuvem/BACKUP.md."
  value       = aws_security_group.db.id
}

output "cache_security_group_id" {
  description = "Security group do cache (6379 só do app)."
  value       = aws_security_group.cache.id
}

output "ec2_instance_profile_name" {
  description = "Nome do instance profile das instâncias do app (launch template da feat/infra-computacao)."
  value       = aws_iam_instance_profile.ec2.name
}

output "ec2_instance_profile_arn" {
  description = "ARN do instance profile das instâncias do app."
  value       = aws_iam_instance_profile.ec2.arn
}

output "ecr_repository_urls" {
  description = "Endereço dos repositórios do ECR, por imagem (app e web). A imagem completa é <endereço>:<tag>."
  value       = { for image, repository in aws_ecr_repository.image : image => repository.repository_url }
}

output "log_group_names" {
  description = "Log groups dos containers no CloudWatch: app (PHP-FPM e agendador) e web (Nginx)."
  value       = { for service, group in aws_cloudwatch_log_group.container : service => group.name }
}

output "db_identifier" {
  description = "Nome do RDS na AWS (--db-instance-identifier nos comandos do docs/nuvem/BACKUP.md)."
  value       = aws_db_instance.barbershop_db.identifier
}

output "db_endpoint" {
  description = "Endereço do RDS, sem a porta. É o valor do parâmetro DB_HOST."
  value       = aws_db_instance.barbershop_db.address
}

output "db_port" {
  description = "Porta do RDS (DB_PORT)."
  value       = aws_db_instance.barbershop_db.port
}

output "db_subnet_group_name" {
  description = "Grupo de subnets privadas do RDS. Vai no restore do docs/nuvem/BACKUP.md."
  value       = aws_db_subnet_group.db.name
}

output "db_parameter_group_name" {
  description = "Parameter group do RDS (TLS obrigatório e log de consultas lentas). Vai no restore do docs/nuvem/BACKUP.md."
  value       = aws_db_parameter_group.mysql.name
}

output "cache_primary_endpoint" {
  description = "Endereço do primário do cache, sem o tls:// e sem a porta. O REDIS_HOST do SSM é tls://<este endereço>."
  value       = aws_elasticache_replication_group.cache.primary_endpoint_address
}

output "ssm_prefix" {
  description = "Caminho dos parâmetros do ambiente no SSM (ex.: /barberflow/homolog). É o --path do aws ssm get-parameters-by-path."
  value       = local.ssm_path
}
