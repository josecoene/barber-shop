# State remoto no S3, com configuração parcial.
# A trava e a criptografia ficam escritas aqui, no Git, e valem em todo init com backend,
# mesmo sem o backend.hcl. O bucket, a key e a região vêm do backend.hcl (copie do
# backend.hcl.example) ou de -backend-config no terraform init.
# O que vem do init vale mais que este bloco: não passe use_lockfile nem encrypt por lá.
# Para validar e testar sem conta AWS, o init é outro: terraform init -backend=false
terraform {
  backend "s3" {
    # Trava do state no próprio S3 (arquivo .tflock ao lado do state), sem tabela no DynamoDB.
    # Sem ela, dois apply ao mesmo tempo gravam um state por cima do outro.
    use_lockfile = true

    # O arquivo do state fica criptografado no S3.
    encrypt = true
  }
}
