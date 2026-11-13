# Repositórios do ECR das duas imagens que vão para a AWS (alvos app e web do
# docker/php/Dockerfile): barberflow-<ambiente>-app e barberflow-<ambiente>-web.
# Nascem aqui, antes do Auto Scaling, para o IAM (iam.tf) apontar para repositórios que
# existem. O deploy da feat/ci-cd faz o push com a tag do commit.
#
# - Tags imutáveis: uma tag aponta sempre para a mesma imagem. Ninguém sobrescreve a imagem
#   que está no ar, e o rollback volta para exatamente o que rodava antes.
# - Scan no push: o ECR procura vulnerabilidades conhecidas em cada imagem nova.
# - Criptografia AES256, com chave da AWS e sem custo (motivo no .trivyignore.yaml).
# - Sem force_delete: o terraform destroy para se o repositório ainda tiver imagens
#   (docs/nuvem/TERRAFORM.md, "Aplicar na AWS").

locals {
  ecr_images = toset(["app", "web"])

  # Limpeza automática. A regra 1 apaga as camadas sem tag que sobram de builds antigos;
  # a 2 guarda só as N imagens com tag mais novas (N na variável ecr_keep_tagged_images).
  # O rollback só volta para uma imagem que a regra 2 ainda não apagou.
  ecr_lifecycle_policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Apaga imagens sem tag 7 dias depois do push"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = 7
        }
        action = {
          type = "expire"
        }
      },
      {
        rulePriority = 2
        description  = "Guarda so as ${var.ecr_keep_tagged_images} imagens com tag mais novas"
        selection = {
          tagStatus      = "tagged"
          tagPatternList = ["*"]
          countType      = "imageCountMoreThan"
          countNumber    = var.ecr_keep_tagged_images
        }
        action = {
          type = "expire"
        }
      },
    ]
  })
}

resource "aws_ecr_repository" "image" {
  for_each = local.ecr_images

  name                 = "${local.name_prefix}-${each.key}"
  image_tag_mutability = "IMMUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = {
    Name = "${local.name_prefix}-${each.key}"
  }
}

resource "aws_ecr_lifecycle_policy" "image" {
  for_each = aws_ecr_repository.image

  repository = each.value.name
  policy     = local.ecr_lifecycle_policy
}
