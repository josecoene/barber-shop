# Variáveis da base e da rede (branch feat/infra-rede), depois as dos dados e da segurança
# (feat/infra-dados-seguranca) e, no fim, as da computação (feat/infra-computacao).
# As próximas branches que mexem no Terraform (feat/ci-cd e feat/observabilidade) acrescentam
# as delas no fim deste arquivo.
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

# Dados e segurança (branch feat/infra-dados-seguranca): RDS, cache, ECR, logs e SSM.
# Todas têm valor padrão, para os testes e o validate rodarem só com o environment.

variable "db_deletion_protection" {
  description = "Proteção contra exclusão do RDS. Com true, nem o terraform destroy apaga o banco. Ligada na prod; na homolog fica desligada, para o ambiente poder ser destruído depois da apresentação."
  type        = bool
  default     = true
}

variable "db_multi_az" {
  description = "RDS com uma réplica de espera em outra zona (Multi-AZ), que assume sozinha se a zona do banco cair. Dobra o custo do banco."
  type        = bool
  default     = false
}

variable "cache_node_type" {
  description = "Tipo de nó do ElastiCache (Valkey)."
  type        = string
  default     = "cache.t4g.micro"

  validation {
    condition     = can(regex("^cache\\.[a-z0-9]+\\.[a-z0-9]+$", var.cache_node_type))
    error_message = "Use um tipo de nó do ElastiCache, ex.: cache.t4g.micro."
  }
}

variable "cache_num_cache_clusters" {
  description = "Quantos nós o cache tem: 1 é só o primário; 2 ou mais põem réplicas em outras zonas."
  type        = number
  default     = 1

  validation {
    condition     = var.cache_num_cache_clusters >= 1 && var.cache_num_cache_clusters <= 6 && floor(var.cache_num_cache_clusters) == var.cache_num_cache_clusters
    error_message = "Use de 1 a 6 nós, o limite do ElastiCache sem cluster mode."
  }
}

variable "cache_automatic_failover" {
  description = "Failover automático e Multi-AZ do cache: se o primário cair, uma réplica de outra zona assume. Precisa de cache_num_cache_clusters 2 ou mais."
  type        = bool
  default     = false

  validation {
    condition     = !var.cache_automatic_failover || var.cache_num_cache_clusters >= 2
    error_message = "O failover automático precisa de pelo menos 2 nós (cache_num_cache_clusters = 2 ou mais)."
  }
}

variable "ecr_keep_tagged_images" {
  description = "Quantas imagens com tag cada repositório do ECR guarda. As mais antigas são apagadas, e o rollback só volta para uma imagem que ainda está lá."
  type        = number
  default     = 10

  validation {
    condition     = var.ecr_keep_tagged_images >= 2 && var.ecr_keep_tagged_images <= 100 && floor(var.ecr_keep_tagged_images) == var.ecr_keep_tagged_images
    error_message = "Use de 2 a 100 imagens. Com menos de 2, o rollback não tem imagem anterior para onde voltar."
  }
}

variable "log_retention_days" {
  description = "Dias que os logs ficam guardados no CloudWatch: containers app e web e os logs de erro e de consultas lentas do RDS."
  type        = number
  default     = 30

  validation {
    condition     = contains([1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288, 3653], var.log_retention_days)
    error_message = "Use um dos prazos que o CloudWatch aceita: 1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1096, 1827, 2192, 2557, 2922, 3288 ou 3653 dias."
  }
}

variable "app_url" {
  description = "Endereço público da aplicação (APP_URL do Laravel), ex.: https://barberflow.com.br. Vazio enquanto o grupo não tiver domínio: aí o APP_URL é o DNS do ALB, em HTTP (alb.tf). Com https://, precisa do certificate_arn."
  type        = string
  default     = ""

  validation {
    condition     = var.app_url == "" || can(regex("^https?://[a-z0-9.-]+(:[0-9]+)?$", var.app_url))
    error_message = "Deixe vazio ou use http(s)://endereço, sem barra nem caminho no fim, ex.: https://barberflow.com.br."
  }

  # app_url e certificate_arn andam juntos. As duas conferências ficam aqui, e não no
  # certificate_arn: validações das duas variáveis citando uma à outra formariam um ciclo.
  # Sem certificado, o ALB não tem a porta 443, e um APP_URL em https levaria a um endereço fora do ar.
  validation {
    condition     = !startswith(var.app_url, "https://") || var.certificate_arn != ""
    error_message = "Um app_url com https:// precisa do certificate_arn (o certificado do ACM para o ALB). Sem certificado, use http:// ou deixe vazio."
  }

  # O certificado vale para o domínio, não para o DNS do ALB: sem um app_url em https, o
  # navegador recusaria o certificado e os links dos e-mails sairiam com o endereço errado.
  validation {
    condition     = var.certificate_arn == "" || startswith(var.app_url, "https://")
    error_message = "Com certificate_arn, preencha o app_url com https://<domínio do certificado>."
  }
}

variable "mail_mailer" {
  description = "Transporte de e-mail do Laravel (MAIL_MAILER): smtp, com o provedor de e-mail, ou array, que descarta os e-mails (só para teste)."
  type        = string
  default     = "smtp"

  validation {
    condition     = contains(["smtp", "array"], var.mail_mailer)
    error_message = "Use smtp ou array. Os outros transportes do Laravel pedem pacotes que o projeto não tem, e o log gravaria no CloudWatch os links de confirmação de e-mail e de troca de senha."
  }
}

variable "mail_from_address" {
  description = "Remetente dos e-mails (MAIL_FROM_ADDRESS). Use um endereço do domínio que o provedor de e-mail verificou."
  type        = string
  default     = "nao-responda@example.com"

  validation {
    condition     = can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.mail_from_address))
    error_message = "Use um e-mail válido, ex.: nao-responda@barberflow.com.br."
  }
}

variable "mail_host" {
  description = "Servidor SMTP do provedor de e-mail (MAIL_HOST), ex.: email-smtp.us-east-1.amazonaws.com. Vazio enquanto o grupo não escolher o provedor: aí o parâmetro MAIL_HOST não é criado e o e-mail não sai."
  type        = string
  default     = ""

  validation {
    condition     = var.mail_host == "" || can(regex("^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$", var.mail_host))
    error_message = "Deixe vazio ou use só o nome do servidor, em letras minúsculas, sem protocolo e sem porta, ex.: email-smtp.us-east-1.amazonaws.com."
  }
}

variable "mail_port" {
  description = "Porta SMTP do provedor de e-mail (MAIL_PORT). A saída do app (sg.tf) abre para a internet só esta porta, além da 443."
  type        = number
  default     = 587

  validation {
    condition     = var.mail_port >= 1 && var.mail_port <= 65535 && floor(var.mail_port) == var.mail_port && !contains([25, 443], var.mail_port)
    error_message = "Use a porta de envio do provedor (a mais comum é 587). A 25 fica de fora porque a AWS bloqueia essa porta nas instâncias EC2, e a 443 porque já está liberada para o HTTPS."
  }
}

# Computação (branch feat/infra-computacao): ALB, launch template, Auto Scaling e orçamento.
# Todas têm valor padrão, como as anteriores.

variable "certificate_arn" {
  description = "ARN do certificado do ACM para o HTTPS do ALB, na mesma região. Vazio (padrão) enquanto o grupo não tiver domínio: o ALB atende só HTTP na 80. Com o certificado, a 80 redireciona para a 443, e o app_url precisa ser https://<domínio do certificado>."
  type        = string
  default     = ""

  validation {
    condition     = var.certificate_arn == "" || can(regex("^arn:aws:acm:[a-z0-9-]+:[0-9]{12}:certificate/[0-9a-f-]+$", var.certificate_arn))
    error_message = "Deixe vazio ou use o ARN de um certificado do ACM, ex.: arn:aws:acm:us-east-1:123456789012:certificate/<id>."
  }

  # O ALB só usa certificado da própria região. A combinação com o app_url é conferida na
  # variável app_url.
  validation {
    condition     = var.certificate_arn == "" || try(split(":", var.certificate_arn)[3] == var.region, false)
    error_message = "O certificado precisa estar na mesma região do ALB (variável region)."
  }
}

variable "alb_deletion_protection" {
  description = "Proteção contra exclusão do ALB. Com true, nem o terraform destroy apaga o ALB. Ligada na prod; na homolog fica desligada, para o ambiente poder ser destruído depois da apresentação."
  type        = bool
  default     = true
}

variable "instance_type" {
  description = "Tipo das instâncias do app. A AMI e as imagens Docker são x86_64, então tipos Graviton (arm64, como t4g e m7g) ficam de fora."
  type        = string
  default     = "t3.micro"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]*\\.[a-z0-9]+$", var.instance_type)) && !can(regex("^[a-z]+[0-9]+g", var.instance_type)) && !startswith(var.instance_type, "a1.")
    error_message = "Use um tipo x86_64, ex.: t3.micro ou t3.small. Os Graviton (t4g, m7g e outros) pediriam AMI e imagens arm64."
  }
}

variable "root_volume_size" {
  description = "Tamanho do disco das instâncias, em GB (gp3 criptografado): sistema, Docker e imagens."
  type        = number
  default     = 30

  validation {
    condition     = var.root_volume_size >= 20 && var.root_volume_size <= 100 && floor(var.root_volume_size) == var.root_volume_size
    error_message = "Use de 20 a 100 GB. Menos que 20 aperta o Docker e as imagens; mais que 100 só aumenta o custo."
  }
}

variable "asg_min_size" {
  description = "Mínimo de instâncias no Auto Scaling. 0 só no primeiro deploy, antes de existir imagem no ECR."
  type        = number
  default     = 1

  validation {
    condition     = var.asg_min_size >= 0 && floor(var.asg_min_size) == var.asg_min_size
    error_message = "Use um número inteiro de 0 para cima. O 0 serve para o primeiro deploy."
  }
}

variable "asg_desired_capacity" {
  description = "Quantas instâncias o Auto Scaling mantém no ar, entre o mínimo e o máximo; a escala por CPU ajusta depois. 0 só no primeiro deploy."
  type        = number
  default     = 1

  validation {
    condition     = floor(var.asg_desired_capacity) == var.asg_desired_capacity && var.asg_desired_capacity >= var.asg_min_size && var.asg_desired_capacity <= var.asg_max_size
    error_message = "Use um número inteiro entre o asg_min_size e o asg_max_size."
  }
}

variable "asg_max_size" {
  description = "Máximo de instâncias no Auto Scaling: o teto da escala por CPU e do custo."
  type        = number
  default     = 2

  validation {
    condition     = var.asg_max_size >= 1 && var.asg_max_size <= 10 && floor(var.asg_max_size) == var.asg_max_size && var.asg_max_size >= var.asg_min_size
    error_message = "Use um número inteiro de 1 a 10, maior ou igual ao asg_min_size."
  }
}

variable "asg_cpu_target" {
  description = "CPU média (%) que a escala do Auto Scaling persegue: acima dela cria instância, abaixo remove."
  type        = number
  default     = 60

  validation {
    condition     = var.asg_cpu_target >= 20 && var.asg_cpu_target <= 90
    error_message = "Use de 20 a 90 (%). Abaixo de 20 o grupo cresce à toa; acima de 90 a escala chega tarde."
  }
}

variable "budget_limit_usd" {
  description = "Limite do orçamento mensal da conta, em dólares (budget.tf)."
  type        = number
  default     = 30

  validation {
    condition     = var.budget_limit_usd >= 1 && var.budget_limit_usd <= 10000
    error_message = "Use de 1 a 10000 dólares."
  }
}

variable "budget_alert_email" {
  description = "E-mail que recebe os avisos do orçamento (80% do gasto real e 100% do previsto). Vazio (padrão), o orçamento não é criado."
  type        = string
  default     = ""

  validation {
    condition     = var.budget_alert_email == "" || can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.budget_alert_email))
    error_message = "Deixe vazio ou use um e-mail válido, ex.: financeiro@barberflow.com.br."
  }
}
