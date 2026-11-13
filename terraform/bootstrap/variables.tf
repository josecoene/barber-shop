variable "project" {
  description = "Nome do projeto. Vai no nome do bucket e na tag Project."
  type        = string
  default     = "barberflow"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{1,19}$", var.project))
    error_message = "Use de 2 a 20 caracteres: letras minúsculas, números e hífen, começando por letra."
  }
}

variable "region" {
  description = "Região do bucket. Use a mesma do backend.hcl."
  type        = string
  default     = "us-east-1"
}
