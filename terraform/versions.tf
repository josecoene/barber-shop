# Versões do Terraform e do provider AWS.
# 1.11 ou mais novo: trava do state no próprio S3 (use_lockfile) e atributos write-only.
# A versão exata do provider fica no .terraform.lock.hcl, que vai para o Git.
terraform {
  required_version = ">= 1.11"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.73"
    }
  }
}
