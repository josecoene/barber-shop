# Variáveis da base e da rede (branch feat/infra-rede).
# As branches seguintes que mexem no Terraform (feat/infra-dados-seguranca,
# feat/infra-computacao, feat/ci-cd e feat/observabilidade) acrescentam as delas no fim
# deste arquivo.
# Os valores de cada ambiente ficam em envs/<ambiente>.tfvars (copie do .tfvars.example).

variable "project" {
  description = "Nome do projeto. Vai no começo do nome de cada recurso e na tag Project."
  type        = string
  default     = "barberflow"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{1,19}$", var.project))
    error_message = "Use de 2 a 20 caracteres: letras minúsculas, números e hífen, começando por letra."
  }
}

variable "environment" {
  description = "Ambiente: homolog ou prod. Não tem valor padrão, para ninguém aplicar no ambiente errado sem querer."
  type        = string

  validation {
    condition     = contains(["homolog", "prod"], var.environment)
    error_message = "O ambiente precisa ser homolog ou prod."
  }
}

variable "region" {
  description = "Região da AWS onde tudo é criado."
  type        = string
  default     = "us-east-1"
}

variable "vpc_cidr" {
  description = "Faixa de IPs da VPC, de /16 a /19. Cada subnet recebe 1/256 dela: um /24 com a VPC /16 e um /27 com a /19."
  type        = string
  default     = "10.20.0.0/16"

  # Para no /19 porque cada subnet sai com 8 bits a mais (network.tf) e o ALB pede subnet
  # de /27 ou maior, com pelo menos 8 IPs livres. Uma VPC /20 daria subnets /28.
  validation {
    condition     = can(regex("/1[6-9]$", var.vpc_cidr)) && try(cidrsubnet(var.vpc_cidr, 0, 0) == var.vpc_cidr, false)
    error_message = "Use um bloco de /16 a /19 que comece no endereço de rede, ex.: 10.20.0.0/16. Menor que /19, as subnets ficam menores que /27, o mínimo do ALB."
  }
}

variable "az_count" {
  description = "Quantas zonas de disponibilidade usar. Cada zona recebe uma subnet pública e uma privada."
  type        = number
  default     = 2

  validation {
    condition     = var.az_count >= 2 && var.az_count <= 4 && floor(var.az_count) == var.az_count
    error_message = "Use de 2 a 4 zonas. O ALB e o grupo de subnets do RDS pedem pelo menos 2."
  }
}
