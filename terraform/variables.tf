# Variáveis da base e da rede (branch feat/infra-rede), depois as dos dados e da segurança
# (feat/infra-dados-seguranca), as da computação (feat/infra-computacao), as do CI/CD
# (feat/ci-cd) e, no fim, as da observabilidade (feat/observabilidade). Variável nova entra no
# fim deste arquivo.
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

# CI/CD (branch feat/ci-cd): a role que o deploy do GitHub Actions assume por OIDC (cicd.tf).
# Todas têm valor padrão, como as anteriores.

# Sem a role por padrão: o GitHub cria sem nenhuma proteção o environment que um workflow cita e
# que ainda não existe, e a role aceitaria um workflow de qualquer branch (docs/nuvem/CI-CD.md).
variable "create_deploy_role" {
  description = "Cria a role de deploy do GitHub Actions (OIDC) e a política dela. Use true só no ambiente cujo environment do GitHub (github_environment) já existe com revisores obrigatórios e branches restritas: true no prod (environment production do deploy.yml) e false nos outros."
  type        = bool
  default     = false
}

variable "create_github_oidc_provider" {
  description = "Cria o provedor OIDC do GitHub Actions (token.actions.githubusercontent.com) na conta. A AWS aceita um só por conta: true no ambiente que cria (o prod, no exemplo) e false nos outros, que leem o que já existe quando também têm a role (create_deploy_role)."
  type        = bool
  default     = false
}

variable "github_repository" {
  description = "Repositório do GitHub (dono/nome) cujo deploy pode assumir a role, escrito como aparece no GitHub (maiúsculas e minúsculas contam)."
  type        = string
  default     = "josecoene/barber-shop"

  validation {
    condition     = can(regex("^[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+$", var.github_repository))
    error_message = "Use dono/repositório, sem curinga e sem espaço, ex.: josecoene/barber-shop."
  }
}

# IDs do formato imutável do sub do token OIDC (repo:dono@ID/repositório@ID:...), que o GitHub
# usa nos repositórios criados depois de 15/07/2026 e nos que ligaram o formato à mão. Vazios, a
# role aceita só o formato com os nomes.
variable "github_repository_owner_id" {
  description = "ID numérico do dono do repositório no GitHub (owner.id na API), para o formato imutável do sub. Vazio enquanto não for preenchido."
  type        = string
  default     = ""

  validation {
    condition     = can(regex("^([1-9][0-9]*)?$", var.github_repository_owner_id))
    error_message = "Deixe vazio ou use só o número do ID do dono (owner.id), ex.: 123456."
  }
}

variable "github_repository_id" {
  description = "ID numérico do repositório no GitHub (id na API), para o formato imutável do sub. Vazio enquanto não for preenchido; vai junto com o github_repository_owner_id."
  type        = string
  default     = ""

  validation {
    condition     = can(regex("^([1-9][0-9]*)?$", var.github_repository_id))
    error_message = "Deixe vazio ou use só o número do ID do repositório (id), ex.: 456789."
  }

  # Os dois IDs andam juntos: o formato imutável leva os dois.
  validation {
    condition     = (var.github_repository_id == "") == (var.github_repository_owner_id == "")
    error_message = "Preencha os dois IDs (github_repository_owner_id e github_repository_id) ou nenhum."
  }
}

variable "github_environment" {
  description = "Environment do GitHub (Settings > Environments) cujo deploy pode assumir a role, escrito como aparece no GitHub. É o environment do .github/workflows/deploy.yml, com a aprovação do grupo."
  type        = string
  default     = "production"

  validation {
    condition     = can(regex("^[A-Za-z0-9._-]{1,255}$", var.github_environment))
    error_message = "Use só letras, números, ponto, hífen e sublinhado, sem curinga, espaço ou dois-pontos, ex.: production."
  }
}

# Observabilidade (branch feat/observabilidade): o e-mail dos alarmes do CloudWatch e os limites
# de cada alarme (monitoring.tf). Todas têm valor padrão, como as anteriores. O limite do alarme
# de instâncias em serviço é o asg_min_size, da computação.

variable "alarm_email" {
  description = "E-mail que recebe os alarmes do CloudWatch pelo tópico SNS do ambiente. Vazio (padrão), o tópico e os alarmes existem, mas ninguém é avisado. A AWS manda um e-mail de confirmação, e o aviso só chega depois do clique no link."
  type        = string
  default     = ""

  validation {
    condition     = var.alarm_email == "" || can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.alarm_email))
    error_message = "Deixe vazio ou use um e-mail válido, ex.: alertas@barberflow.com.br."
  }
}

variable "alarm_http_5xx_threshold" {
  description = "Respostas 5xx em 5 minutos acima das quais os dois alarmes de 5xx disparam: um para as do próprio ALB e outro para as das instâncias."
  type        = number
  default     = 10

  validation {
    condition     = var.alarm_http_5xx_threshold >= 0 && var.alarm_http_5xx_threshold <= 100000 && floor(var.alarm_http_5xx_threshold) == var.alarm_http_5xx_threshold
    error_message = "Use um número inteiro de 0 a 100000. Com 0, qualquer resposta 5xx dispara o alarme."
  }
}

variable "alarm_target_p95_seconds" {
  description = "Tempo de resposta das instâncias (p95, em segundos) acima do qual o alarme de latência dispara, depois de 15 minutos seguidos. O padrão é a meta dos testes de carga (load-tests/README.md)."
  type        = number
  default     = 0.5

  validation {
    condition     = var.alarm_target_p95_seconds > 0 && var.alarm_target_p95_seconds <= 60
    error_message = "Use de 0 (sem contar) a 60 segundos, ex.: 0.5."
  }
}

variable "alarm_unhealthy_hosts_threshold" {
  description = "Instâncias sem saúde no target group acima das quais o alarme dispara, depois de 5 minutos seguidos. Com 0 (padrão), basta uma."
  type        = number
  default     = 0

  validation {
    condition     = var.alarm_unhealthy_hosts_threshold >= 0 && var.alarm_unhealthy_hosts_threshold <= 10 && floor(var.alarm_unhealthy_hosts_threshold) == var.alarm_unhealthy_hosts_threshold
    error_message = "Use um número inteiro de 0 a 10, o máximo de instâncias do Auto Scaling."
  }
}

variable "alarm_db_cpu_percent" {
  description = "CPU do banco (%) acima da qual o alarme dispara, depois de 5 minutos seguidos."
  type        = number
  default     = 80

  validation {
    condition     = var.alarm_db_cpu_percent >= 1 && var.alarm_db_cpu_percent <= 100
    error_message = "Use de 1 a 100 (%)."
  }
}

variable "alarm_db_free_storage_gb" {
  description = "Espaço livre no disco do banco (GB) abaixo do qual o alarme dispara. O padrão é 10% dos 20 GB do RDS, como a AWS recomenda. Precisa ser menor que o disco."
  type        = number
  default     = 2

  validation {
    condition     = var.alarm_db_free_storage_gb > 0
    error_message = "Use um número maior que 0 (GB). O monitoring.tf também confere que o limite é menor que o disco do banco."
  }
}

variable "alarm_db_free_memory_mb" {
  description = "Memória livre do banco (MB) abaixo da qual o alarme dispara, depois de 15 minutos seguidos. O db.t3.micro tem 1 GB, e o MySQL usa a maior parte para o cache das tabelas."
  type        = number
  default     = 100

  validation {
    condition     = var.alarm_db_free_memory_mb > 0 && var.alarm_db_free_memory_mb <= 1048576
    error_message = "Use de 1 a 1048576 (MB)."
  }
}

variable "alarm_db_connections" {
  description = "Conexões abertas no banco acima das quais o alarme dispara, depois de 5 minutos seguidos. O db.t3.micro aceita perto de 60; o padrão avisa antes do limite."
  type        = number
  default     = 50

  validation {
    condition     = var.alarm_db_connections >= 1 && floor(var.alarm_db_connections) == var.alarm_db_connections
    error_message = "Use um número inteiro de 1 para cima."
  }
}

variable "alarm_cache_cpu_percent" {
  description = "CPU do Valkey (%) em cada nó do cache acima da qual o alarme dispara, depois de 5 minutos seguidos. O padrão é o que a AWS recomenda para a CPU do engine."
  type        = number
  default     = 90

  validation {
    condition     = var.alarm_cache_cpu_percent >= 1 && var.alarm_cache_cpu_percent <= 100
    error_message = "Use de 1 a 100 (%)."
  }
}

variable "alarm_cache_memory_percent" {
  description = "Memória usada do Valkey (% do maxmemory) em cada nó do cache acima da qual o alarme dispara, depois de 5 minutos seguidos."
  type        = number
  default     = 80

  validation {
    condition     = var.alarm_cache_memory_percent >= 1 && var.alarm_cache_memory_percent <= 100
    error_message = "Use de 1 a 100 (%)."
  }
}

variable "alarm_cache_evictions" {
  description = "Chaves jogadas fora por falta de memória (evictions) em 5 minutos, em cada nó do cache, acima das quais o alarme dispara. Com 0 (padrão), basta uma: com as sessões no cache, cada eviction pode deslogar alguém."
  type        = number
  default     = 0

  validation {
    condition     = var.alarm_cache_evictions >= 0 && floor(var.alarm_cache_evictions) == var.alarm_cache_evictions
    error_message = "Use um número inteiro de 0 para cima."
  }
}
