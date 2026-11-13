# Log groups do CloudWatch, todos com a retenção da variável log_retention_days (padrão 30 dias).
#
# Containers: /barberflow/<ambiente>/app (PHP-FPM e agendador) e /barberflow/<ambiente>/web
# (Nginx). Quem escreve é o driver awslogs do Docker da EC2 (feat/infra-computacao), com a
# permissão da role da instância (iam.tf), que só alcança estes dois grupos.
#
# RDS: os grupos dos logs de erro e de consultas lentas que o banco exporta (main.tf).
# Se não existirem, o RDS cria os dois sozinho, sem prazo de validade, e o log cresce para
# sempre. Criados aqui antes do banco (depends_on no main.tf), seguem a retenção da variável.
#
# Criptografia: a padrão do CloudWatch, com chave da AWS (motivo no .trivyignore.yaml).

resource "aws_cloudwatch_log_group" "container" {
  for_each = local.ecr_images

  name              = "/${var.project}/${var.environment}/${each.key}"
  retention_in_days = var.log_retention_days
}

resource "aws_cloudwatch_log_group" "rds" {
  for_each = toset(["error", "slowquery"])

  # Nome fixo que o RDS usa: /aws/rds/instance/<identifier do banco>/<tipo do log>
  name              = "/aws/rds/instance/${local.db_identifier}/${each.key}"
  retention_in_days = var.log_retention_days
}
