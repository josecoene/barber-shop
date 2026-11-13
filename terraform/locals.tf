locals {
  # Começo do nome de todos os recursos, ex.: barberflow-homolog-vpc.
  name_prefix = "${var.project}-${var.environment}"

  # Tags padrão, aplicadas pelo default_tags do provider (providers.tf).
  # O default_tags não chega a tudo: as instâncias e os volumes que o launch template cria
  # (tag_specifications) e as tags do Auto Scaling precisam receber local.tags direto.
  tags = {
    Project     = var.project
    Environment = var.environment
    ManagedBy   = "Terraform"
    Course      = "Computacao em Nuvem"
  }
}
