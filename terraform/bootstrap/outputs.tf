output "state_bucket_name" {
  description = "Nome do bucket. Vai na linha bucket do terraform/backend.hcl."
  value       = aws_s3_bucket.state.bucket
}

output "state_bucket_arn" {
  description = "ARN do bucket, para políticas de IAM que precisem ler ou gravar o state."
  value       = aws_s3_bucket.state.arn
}

output "region" {
  description = "Região do bucket. Vai na linha region do terraform/backend.hcl."
  value       = var.region
}
