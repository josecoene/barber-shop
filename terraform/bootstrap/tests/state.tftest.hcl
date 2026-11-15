# Testes do bucket do state com o provider AWS simulado (mock): rodam sem conta.
# Na pasta terraform/bootstrap/: terraform init -backend=false e depois terraform test.

mock_provider "aws" {
  mock_data "aws_caller_identity" {
    defaults = {
      account_id = "123456789012"
    }
  }
}

run "state_bucket" {
  command = apply

  assert {
    condition     = aws_s3_bucket.state.bucket == "barberflow-tfstate-123456789012"
    error_message = "O nome do bucket deve ter o projeto e o ID da conta."
  }

  assert {
    condition     = output.state_bucket_name == aws_s3_bucket.state.bucket
    error_message = "O output state_bucket_name deve mostrar o nome do bucket."
  }

  assert {
    condition     = aws_s3_bucket.state.force_destroy == false
    error_message = "O bucket do state não pode ser apagado com os states dentro (force_destroy)."
  }

  assert {
    condition     = aws_s3_bucket_versioning.state.bucket == aws_s3_bucket.state.id && aws_s3_bucket_versioning.state.versioning_configuration[0].status == "Enabled"
    error_message = "O bucket do state precisa de versionamento ligado."
  }

  assert {
    condition     = aws_s3_bucket_server_side_encryption_configuration.state.bucket == aws_s3_bucket.state.id && alltrue([for r in aws_s3_bucket_server_side_encryption_configuration.state.rule : r.apply_server_side_encryption_by_default[0].sse_algorithm == "AES256"])
    error_message = "O bucket do state precisa de criptografia SSE por padrão."
  }

  assert {
    condition = alltrue([
      aws_s3_bucket_public_access_block.state.bucket == aws_s3_bucket.state.id,
      aws_s3_bucket_public_access_block.state.block_public_acls,
      aws_s3_bucket_public_access_block.state.block_public_policy,
      aws_s3_bucket_public_access_block.state.ignore_public_acls,
      aws_s3_bucket_public_access_block.state.restrict_public_buckets,
    ])
    error_message = "O bucket do state precisa das quatro travas de acesso público ligadas."
  }

  assert {
    condition = anytrue([
      for s in jsondecode(aws_s3_bucket_policy.state.policy).Statement :
      s.Effect == "Deny" && s.Principal == "*" && s.Action == "s3:*" &&
      s.Condition.Bool["aws:SecureTransport"] == "false" &&
      toset(s.Resource) == toset([aws_s3_bucket.state.arn, "${aws_s3_bucket.state.arn}/*"])
    ])
    error_message = "A política do bucket deve negar qualquer acesso sem TLS, no bucket e nos objetos."
  }

  assert {
    condition     = aws_s3_bucket_policy.state.bucket == aws_s3_bucket.state.id
    error_message = "A política deve estar no bucket do state."
  }
}
