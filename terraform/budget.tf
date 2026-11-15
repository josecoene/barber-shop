# Orçamento mensal de custo, com aviso por e-mail (AWS Budgets). Só existe com e-mail na
# variável budget_alert_email: sem ninguém para avisar, ele não serviria para nada.
#
# - Avisa quando o gasto real do mês passa de 80% do limite e quando a previsão da AWS para o
#   fim do mês passa de 100%. O limite fica na variável budget_limit_usd (padrão US$ 30).
# - Olha a conta inteira, e não só os recursos deste ambiente: assim pega também o que alguém
#   esqueceu ligado fora do Terraform. Com homolog e prod na mesma conta, preencha o e-mail num
#   ambiente só, ou os dois mandam o mesmo aviso (docs/nuvem/TERRAFORM.md, "Orçamento").
# - Acompanhar o orçamento e receber os avisos não custa nada (preço do AWS Budgets conferido em
#   13/11/2026). Ele só avisa: não desliga nada sozinho.

resource "aws_budgets_budget" "monthly" {
  count = var.budget_alert_email == "" ? 0 : 1

  name         = "${local.name_prefix}-mensal"
  budget_type  = "COST"
  limit_amount = tostring(var.budget_limit_usd)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 80
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.budget_alert_email]
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = [var.budget_alert_email]
  }

  tags = {
    Name = "${local.name_prefix}-mensal"
  }
}
