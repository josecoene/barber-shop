provider "aws" {
  region = var.region

  # O bucket guarda o state da homolog e da prod, por isso o ambiente é "shared".
  default_tags {
    tags = {
      Project     = var.project
      Environment = "shared"
      ManagedBy   = "Terraform"
      Course      = "Computacao em Nuvem"
    }
  }
}
