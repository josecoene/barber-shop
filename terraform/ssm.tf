# Parâmetros do SSM Parameter Store que viram o .env da EC2, em /barberflow/<ambiente>/.
# O user_data da feat/infra-computacao lê o caminho inteiro (get-parameters-by-path, com
# --with-decryption) e grava uma linha NOME=valor por parâmetro, ao lado do
# docker-compose.prod.yml.
#
# Três grupos, porque o Terraform trata cada um de um jeito:
#   1. ssm_terraform_parameters (String): o Terraform mantém o valor. Mudou aqui ou mudou o
#      endereço do banco ou do cache, o próximo apply atualiza.
#   2. ssm_operator_parameters: o Terraform cria com um valor provisório e não mexe mais.
#      APP_KEY, que o operador preenche, vai pelo atributo write-only value_wo: o Terraform
#      nunca lê o valor de volta, e a chave de verdade não entra no state nem no plano salvo.
#      image_tag, que o deploy da feat/ci-cd troca a cada versão, usa insecure_value com
#      ignore_changes.
#   3. DB_PASSWORD (SecureString): o mesmo valor do password do RDS no main.tf (decisão de
#      13/11), passado por referência no atributo write-only value_wo. Assim o valor não
#      aparece no plan e não ganha uma segunda cópia no state (o state do RDS já guarda a senha).
# Nenhum parâmetro usa o atributo value, que guardaria no state o valor lido da AWS. O teste
# confere.
#
# Usuário e senha do provedor de e-mail (MAIL_USERNAME e MAIL_PASSWORD) são segredos que o
# Terraform não conhece: entram à mão no mesmo caminho, e o user_data lê o caminho inteiro.

locals {
  # Caminho dos parâmetros do ambiente, sem a barra do fim: /barberflow/homolog ou /barberflow/prod.
  ssm_path = "/${var.project}/${var.environment}"

  ssm_terraform_parameters = merge(
    {
      # Nome da aplicação (título das páginas e remetente dos e-mails). Sem ele, o Laravel usa "Laravel".
      APP_NAME = "BarberFlow"

      # Bloco de produção, com os mesmos valores do environment do docker-compose.prod.yml, que
      # vale mais que o .env. Aqui eles deixam o .env da EC2 completo também para quem rodar a
      # imagem fora do compose (ex.: docker run --env-file .env ... php artisan ...).
      APP_ENV     = "production"
      APP_DEBUG   = "false"
      LOG_CHANNEL = "stderr"
      LOG_LEVEL   = "info"

      # Banco. Sem DB_CONNECTION, o Laravel cai no sqlite (padrão do config/database.php).
      DB_CONNECTION = "mysql"
      DB_HOST       = aws_db_instance.barbershop_db.address
      DB_PORT       = tostring(aws_db_instance.barbershop_db.port)
      DB_DATABASE   = aws_db_instance.barbershop_db.db_name
      DB_USERNAME   = aws_db_instance.barbershop_db.username

      # TLS com o RDS: o bundle de CA da AWS que a imagem traz (docker/php/Dockerfile).
      # O parameter group do banco (main.tf) recusa conexão sem TLS.
      MYSQL_ATTR_SSL_CA = "/etc/ssl/certs/rds-global-bundle.pem"

      # Cache e sessão no Valkey, com TLS (o tls:// liga a criptografia na extensão redis).
      REDIS_HOST     = "tls://${aws_elasticache_replication_group.cache.primary_endpoint_address}"
      REDIS_PORT     = tostring(aws_elasticache_replication_group.cache.port)
      CACHE_STORE    = "redis"
      SESSION_DRIVER = "redis"

      # Cookie de sessão também em HTTP, enquanto o ALB não tem certificado. A
      # feat/infra-computacao liga (true) quando houver certificate_arn.
      SESSION_SECURE_COOKIE = "false"

      # Modo de manutenção no Redis: o php artisan down de uma instância vale para todas
      # (docs/nuvem/DOCKER.md, "Produção na EC2").
      APP_MAINTENANCE_DRIVER = "cache"
      APP_MAINTENANCE_STORE  = "redis"

      # O app roda migrate --force --isolated ao subir; o scheduler nunca migra.
      RUN_MIGRATIONS = "true"

      # E-mail. A porta é a mesma que a saída do app (sg.tf) abre para a internet. Usuário e
      # senha do provedor (MAIL_USERNAME e MAIL_PASSWORD) entram à mão.
      MAIL_MAILER       = var.mail_mailer
      MAIL_FROM_ADDRESS = var.mail_from_address
      MAIL_PORT         = tostring(var.mail_port)
    },
    # O SSM não aceita parâmetro vazio. APP_URL só existe com domínio: vazio, a
    # feat/infra-computacao cria com o DNS do ALB. MAIL_HOST só existe com o provedor escolhido.
    { for name, value in { APP_URL = var.app_url, MAIL_HOST = var.mail_host } : name => value if value != "" },
  )

  # Valores provisórios: não são chave nem tag de verdade, e ninguém publica uma imagem com a
  # tag PREENCHER_PELO_DEPLOY (nem com latest, que o projeto não usa). O entrypoint de produção
  # recusa o APP_KEY provisório, e o user_data da feat/infra-computacao para enquanto a
  # image_tag tiver o valor provisório.
  ssm_operator_parameters = {
    APP_KEY = {
      type          = "SecureString"
      initial_value = "PREENCHER_COM_AWS_SSM_PUT_PARAMETER"
      description   = "Chave do Laravel. O operador preenche com aws ssm put-parameter (docs/nuvem/TERRAFORM.md)"
    }
    image_tag = {
      type          = "String"
      initial_value = "PREENCHER_PELO_DEPLOY"
      description   = "Tag das imagens app e web no ECR. O deploy da feat/ci-cd troca a cada versao"
    }
  }
}

resource "aws_ssm_parameter" "terraform_managed" {
  for_each = local.ssm_terraform_parameters

  name = "${local.ssm_path}/${each.key}"
  type = "String"

  # insecure_value: valor que não é segredo, mostrado no plan para quem revisa.
  insecure_value = each.value
}

resource "aws_ssm_parameter" "operator_managed" {
  for_each = local.ssm_operator_parameters

  name        = "${local.ssm_path}/${each.key}"
  type        = each.value.type
  description = each.value.description

  # SecureString (APP_KEY): write-only. O Terraform manda o valor provisório uma vez (versão 1)
  # e nunca lê de volta a chave que o operador gravar depois; nada dela vai para o state.
  # String (image_tag): insecure_value, que aparece no plan.
  value_wo         = each.value.type == "SecureString" ? each.value.initial_value : null
  value_wo_version = each.value.type == "SecureString" ? 1 : null
  insecure_value   = each.value.type == "String" ? each.value.initial_value : null

  # Depois de criada, a image_tag é do deploy. O APP_KEY não precisa disto: o value_wo só é
  # enviado de novo se o value_wo_version mudar.
  lifecycle {
    ignore_changes = [insecure_value]
  }
}

resource "aws_ssm_parameter" "db_password" {
  name        = "${local.ssm_path}/DB_PASSWORD"
  type        = "SecureString"
  description = "Senha do RDS, o mesmo valor do password do terraform/main.tf (decisao de 13/11)"

  # Write-only: o Terraform manda o valor para a AWS e não guarda no state. Ele só manda de
  # novo quando value_wo_version muda: se um dia a senha do main.tf mudar, suba para 2.
  value_wo         = aws_db_instance.barbershop_db.password
  value_wo_version = 1
}
