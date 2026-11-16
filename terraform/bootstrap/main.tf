# Bucket do state remoto da pasta terraform/ (homolog e prod, cada um na sua key).
# O state guarda tudo em texto puro, inclusive senhas. Por isso o bucket é privado,
# criptografado, versionado (dá para voltar um state estragado) e só aceita HTTPS.

data "aws_caller_identity" "current" {}

locals {
  # Nome de bucket é único no mundo todo: o ID da conta entra no nome para não colidir.
  bucket_name = "${var.project}-tfstate-${data.aws_caller_identity.current.account_id}"

  # Política em jsonencode, e não em data source, para o teste com mock conseguir ler.
  bucket_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource = [
          aws_s3_bucket.state.arn,
          "${aws_s3_bucket.state.arn}/*",
        ]
        Condition = {
          Bool = {
            "aws:SecureTransport" = "false"
          }
        }
      },
    ]
  })
}

resource "aws_s3_bucket" "state" {
  bucket = local.bucket_name

  # Sem force_destroy: o Terraform não apaga o bucket enquanto houver state dentro.
  force_destroy = false
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket = aws_s3_bucket.state.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_policy" "state" {
  bucket = aws_s3_bucket.state.id
  policy = local.bucket_policy

  # Aplicar a política junto com o bloqueio de acesso público dá conflito na API do S3.
  depends_on = [aws_s3_bucket_public_access_block.state]
}
