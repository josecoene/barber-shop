# Saídas da rede. Dentro desta pasta, as próximas branches usam os recursos direto
# (aws_vpc.main, aws_subnet.public, aws_subnet.private); as saídas servem para conferir
# o que foi criado e para scripts e pipelines lerem com terraform output.

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
