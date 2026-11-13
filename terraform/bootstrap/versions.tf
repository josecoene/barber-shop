# Stack separada que cria o bucket do state remoto.
# O state desta pasta fica local (terraform.tfstate, ignorado pelo Git): ela cria o bucket
# onde o state da pasta terraform/ vai morar, então não pode guardar o próprio state nele.
terraform {
  required_version = ">= 1.11"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.73"
    }
  }
}
