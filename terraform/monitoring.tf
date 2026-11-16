# Observabilidade na AWS (branch feat/observabilidade): alarmes do CloudWatch com aviso por
# e-mail (SNS) e um dashboard com os números do ALB, do Auto Scaling, do RDS e do ElastiCache.
#
# - Um tópico SNS por ambiente recebe os alarmes (alarm_actions) e a volta ao normal
#   (ok_actions). A assinatura por e-mail só existe com alarm_email preenchido; a AWS manda um
#   e-mail de confirmação, e o aviso só chega depois do clique no link. Sem e-mail, o tópico e os
#   alarmes existem do mesmo jeito: o estado de cada alarme aparece no console e no dashboard.
# - O tópico fica sem chave do KMS: os alarmes do CloudWatch não publicam num tópico criptografado
#   com a chave padrão (aws/sns), e uma chave do grupo custa US$ 1 por mês e pede uma política para
#   o CloudWatch. As mensagens só levam o nome, o estado e o motivo do alarme (.trivyignore.yaml).
# - Os alarmes ficam num recurso só (for_each em local.alarms), e o tests/monitoring.tftest.hcl
#   confere todos de uma vez: cada um aponta para o recurso certo e avisa no tópico.
# - O 5xx cobre o que o log de erro do Nginx deixou de gravar (feat/infra-computacao: o error_log
#   ficou só com crit): o 502 e o 504 do Nginx com o PHP-FPM fora do ar ou lento chegam como 5xx
#   das instâncias (HTTPCode_Target_5XX_Count), e o 502, o 503 e o 504 do próprio ALB, como 5xx do
#   ALB (HTTPCode_ELB_5XX_Count).
# - Os limites vêm de variáveis (variables.tf, no fim). O treat_missing_data segue duas regras:
#   o ALB só manda métrica quando há requisição (sem tráfego, sem erro: notBreaching); RDS e
#   ElastiCache mandam um número por minuto, e uma falta curta (manutenção, reinício) deixa o
#   alarme em INSUFFICIENT_DATA, sem aviso e sem fingir que está tudo bem (missing). A exceção é o
#   Auto Scaling, em que o silêncio é o problema (breaching). Tabela em docs/nuvem/TERRAFORM.md,
#   "Observabilidade".

resource "aws_sns_topic" "alarms" {
  name = "${local.name_prefix}-alarms"

  tags = {
    Name = "${local.name_prefix}-alarms"
  }
}

# Só e-mail: nada de SMS, HTTP ou fila. O endereço vem da variável, nunca de um valor fixo.
resource "aws_sns_topic_subscription" "alarm_email" {
  count = var.alarm_email == "" ? 0 : 1

  topic_arn = aws_sns_topic.alarms.arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

locals {
  # Nós do cache. Sem cluster mode, o ElastiCache dá a cada nó o nome do replication group com
  # -001, -002 e assim por diante no fim, e as métricas do Valkey vêm por nó (dimensão
  # CacheClusterId). O nome sai da configuração, então já é conhecido no plan, e o for_each dos
  # alarmes funciona no primeiro apply (o member_clusters só existe depois de criar o cache).
  cache_node_ids = [
    for i in range(var.cache_num_cache_clusters) :
    format("%s-%03d", aws_elasticache_replication_group.cache.replication_group_id, i + 1)
  ]

  alb_dimensions    = { LoadBalancer = aws_lb.main.arn_suffix }
  target_dimensions = { LoadBalancer = aws_lb.main.arn_suffix, TargetGroup = aws_lb_target_group.app.arn_suffix }
  asg_dimensions    = { AutoScalingGroupName = aws_autoscaling_group.app.name }
  db_dimensions     = { DBInstanceIdentifier = aws_db_instance.barbershop_db.identifier }

  # Cada alarme: o que ele olha, como junta os pontos, o limite e o que fazer sem dado.
  # statistic ou extended_statistic (percentil), nunca os dois. period em segundos.
  app_alarms = {
    "alb-5xx" = {
      description         = "Mais de ${var.alarm_http_5xx_threshold} respostas 5xx do próprio ALB em 5 minutos: 502, 503 ou 504, quando nenhuma instância está saudável ou a instância não respondeu a tempo. Veja docs/nuvem/TERRAFORM.md, Observabilidade."
      namespace           = "AWS/ApplicationELB"
      metric_name         = "HTTPCode_ELB_5XX_Count"
      dimensions          = local.alb_dimensions
      statistic           = "Sum"
      extended_statistic  = null
      period              = 300
      evaluation_periods  = 1
      datapoints_to_alarm = 1
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_http_5xx_threshold
      treat_missing_data  = "notBreaching"
    }
    "target-5xx" = {
      description         = "Mais de ${var.alarm_http_5xx_threshold} respostas 5xx das instâncias em 5 minutos: erro 500 do Laravel, ou 502 e 504 do Nginx com o PHP-FPM fora do ar ou lento (o log de erro do Nginx só grava crit). Veja o log do app no CloudWatch."
      namespace           = "AWS/ApplicationELB"
      metric_name         = "HTTPCode_Target_5XX_Count"
      dimensions          = local.target_dimensions
      statistic           = "Sum"
      extended_statistic  = null
      period              = 300
      evaluation_periods  = 1
      datapoints_to_alarm = 1
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_http_5xx_threshold
      treat_missing_data  = "notBreaching"
    }
    "target-p95" = {
      description         = "p95 do tempo de resposta das instâncias acima de ${replace(tostring(var.alarm_target_p95_seconds), ".", ",")} s por 15 minutos seguidos (a meta dos testes de carga é 0,5 s)."
      namespace           = "AWS/ApplicationELB"
      metric_name         = "TargetResponseTime"
      dimensions          = local.target_dimensions
      statistic           = null
      extended_statistic  = "p95"
      period              = 300
      evaluation_periods  = 3
      datapoints_to_alarm = 3
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_target_p95_seconds
      treat_missing_data  = "notBreaching"
    }
    # Minimum: a instância está sem saúde para todos os nós do ALB, e não só para um (a
    # recomendação da AWS para este alarme). 5 minutos seguidos: uma instância que nasce
    # quebrada fica sem saúde até o Auto Scaling trocar, e o ciclo se repete.
    "target-unhealthy" = {
      description         = "Instâncias sem saúde no target group (o /up não respondeu 200) acima de ${var.alarm_unhealthy_hosts_threshold} por 5 minutos seguidos. Veja o log da subida da instância (docs/nuvem/TERRAFORM.md, Entrar na instância)."
      namespace           = "AWS/ApplicationELB"
      metric_name         = "UnHealthyHostCount"
      dimensions          = local.target_dimensions
      statistic           = "Minimum"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 5
      datapoints_to_alarm = 5
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_unhealthy_hosts_threshold
      treat_missing_data  = "notBreaching"
    }
    # O limite é o mínimo do próprio Auto Scaling (asg_min_size). Com as métricas do grupo
    # ligadas (compute.tf), a AWS manda um número por minuto; 5 minutos sem nenhum quer dizer que
    # o grupo ou as métricas sumiram, e isso também alarma (breaching). Na homolog, com 1
    # instância, todo instance refresh (o que o apply começa quando o launch template muda, ou o
    # feito à mão) deixa o grupo sem instância em serviço por alguns minutos e dispara este
    # alarme: é o aviso de que o app ficou fora do ar.
    "asg-in-service" = {
      description         = "Instâncias em serviço no Auto Scaling abaixo do mínimo do grupo (${var.asg_min_size}) por 5 minutos seguidos, ou o grupo sem métrica nenhuma. Veja o Auto Scaling e o target group no console."
      namespace           = "AWS/AutoScaling"
      metric_name         = "GroupInServiceInstances"
      dimensions          = local.asg_dimensions
      statistic           = "Minimum"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 5
      datapoints_to_alarm = 5
      comparison_operator = "LessThanThreshold"
      threshold           = var.asg_min_size
      treat_missing_data  = "breaching"
    }
    "db-cpu" = {
      description         = "CPU do banco (RDS) acima de ${var.alarm_db_cpu_percent}% por 5 minutos seguidos. No db.t3.micro, CPU alta por muito tempo gasta os créditos e cobra a mais."
      namespace           = "AWS/RDS"
      metric_name         = "CPUUtilization"
      dimensions          = local.db_dimensions
      statistic           = "Average"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 5
      datapoints_to_alarm = 5
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_db_cpu_percent
      treat_missing_data  = "missing"
    }
    "db-free-storage" = {
      description         = "Menos de ${var.alarm_db_free_storage_gb} GB livres no disco do banco (RDS) por 5 minutos seguidos. Sem espaço, o MySQL para de gravar."
      namespace           = "AWS/RDS"
      metric_name         = "FreeStorageSpace"
      dimensions          = local.db_dimensions
      statistic           = "Minimum"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 5
      datapoints_to_alarm = 5
      comparison_operator = "LessThanThreshold"
      threshold           = var.alarm_db_free_storage_gb * 1024 * 1024 * 1024
      treat_missing_data  = "missing"
    }
    # 15 minutos, como a AWS recomenda: memória baixa por pouco tempo é normal no MySQL.
    "db-free-memory" = {
      description         = "Menos de ${var.alarm_db_free_memory_mb} MB de memória livre no banco (RDS) por 15 minutos seguidos."
      namespace           = "AWS/RDS"
      metric_name         = "FreeableMemory"
      dimensions          = local.db_dimensions
      statistic           = "Average"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 15
      datapoints_to_alarm = 15
      comparison_operator = "LessThanThreshold"
      threshold           = var.alarm_db_free_memory_mb * 1024 * 1024
      treat_missing_data  = "missing"
    }
    "db-connections" = {
      description         = "Mais de ${var.alarm_db_connections} conexões abertas no banco (RDS) por 5 minutos seguidos. O db.t3.micro aceita perto de 60."
      namespace           = "AWS/RDS"
      metric_name         = "DatabaseConnections"
      dimensions          = local.db_dimensions
      statistic           = "Average"
      extended_statistic  = null
      period              = 60
      evaluation_periods  = 5
      datapoints_to_alarm = 5
      comparison_operator = "GreaterThanThreshold"
      threshold           = var.alarm_db_connections
      treat_missing_data  = "missing"
    }
  }

  # Três alarmes por nó do cache: CPU do engine (o Valkey roda numa thread só), memória usada e
  # evictions (chaves jogadas fora por falta de memória: com as sessões no cache, é gente
  # deslogada).
  cache_alarms = merge([
    for i, node in local.cache_node_ids : {
      "cache-${format("%03d", i + 1)}-cpu" = {
        description         = "CPU do Valkey no nó ${node} acima de ${var.alarm_cache_cpu_percent}% por 5 minutos seguidos."
        namespace           = "AWS/ElastiCache"
        metric_name         = "EngineCPUUtilization"
        dimensions          = { CacheClusterId = node }
        statistic           = "Average"
        extended_statistic  = null
        period              = 60
        evaluation_periods  = 5
        datapoints_to_alarm = 5
        comparison_operator = "GreaterThanThreshold"
        threshold           = var.alarm_cache_cpu_percent
        treat_missing_data  = "missing"
      }
      "cache-${format("%03d", i + 1)}-memory" = {
        description         = "Memória do Valkey no nó ${node} acima de ${var.alarm_cache_memory_percent}% do maxmemory por 5 minutos seguidos. Cheio, ele joga chaves fora (evictions)."
        namespace           = "AWS/ElastiCache"
        metric_name         = "DatabaseMemoryUsagePercentage"
        dimensions          = { CacheClusterId = node }
        statistic           = "Average"
        extended_statistic  = null
        period              = 60
        evaluation_periods  = 5
        datapoints_to_alarm = 5
        comparison_operator = "GreaterThanThreshold"
        threshold           = var.alarm_cache_memory_percent
        treat_missing_data  = "missing"
      }
      "cache-${format("%03d", i + 1)}-evictions" = {
        description         = "Chaves jogadas fora por falta de memória (evictions) no nó ${node} acima de ${var.alarm_cache_evictions} em 5 minutos. Com as sessões no cache, quem estava logado precisa entrar de novo."
        namespace           = "AWS/ElastiCache"
        metric_name         = "Evictions"
        dimensions          = { CacheClusterId = node }
        statistic           = "Sum"
        extended_statistic  = null
        period              = 300
        evaluation_periods  = 1
        datapoints_to_alarm = 1
        comparison_operator = "GreaterThanThreshold"
        threshold           = var.alarm_cache_evictions
        treat_missing_data  = "missing"
      }
    }
  ]...)

  alarms = merge(local.app_alarms, local.cache_alarms)
}

resource "aws_cloudwatch_metric_alarm" "main" {
  for_each = local.alarms

  alarm_name          = "${local.name_prefix}-${each.key}"
  alarm_description   = each.value.description
  namespace           = each.value.namespace
  metric_name         = each.value.metric_name
  dimensions          = each.value.dimensions
  statistic           = each.value.statistic
  extended_statistic  = each.value.extended_statistic
  period              = each.value.period
  evaluation_periods  = each.value.evaluation_periods
  datapoints_to_alarm = each.value.datapoints_to_alarm
  comparison_operator = each.value.comparison_operator
  threshold           = each.value.threshold
  treat_missing_data  = each.value.treat_missing_data

  # Avisa quando dispara e quando volta ao normal. Sem aviso em INSUFFICIENT_DATA.
  actions_enabled = true
  alarm_actions   = [aws_sns_topic.alarms.arn]
  ok_actions      = [aws_sns_topic.alarms.arn]

  tags = {
    Name = "${local.name_prefix}-${each.key}"
  }

  lifecycle {
    # O limite do espaço livre precisa caber no disco do banco (allocated_storage do main.tf).
    precondition {
      condition     = each.key != "db-free-storage" || var.alarm_db_free_storage_gb < aws_db_instance.barbershop_db.allocated_storage
      error_message = "O alarm_db_free_storage_gb precisa ser menor que o disco do banco (allocated_storage do main.tf, em GB), senão o alarme fica sempre disparado."
    }
  }
}

locals {
  # Linha do limite do alarme num gráfico do dashboard.
  dashboard_alarm_line = {
    db_cpu          = { horizontal = [{ label = "Alarme", value = var.alarm_db_cpu_percent }] }
    db_connections  = { horizontal = [{ label = "Alarme", value = var.alarm_db_connections }] }
    db_free_storage = { horizontal = [{ label = "Alarme", value = var.alarm_db_free_storage_gb * 1024 * 1024 * 1024 }] }
    db_free_memory  = { horizontal = [{ label = "Alarme", value = var.alarm_db_free_memory_mb * 1024 * 1024 }] }
    cache_cpu       = { horizontal = [{ label = "Alarme (Valkey)", value = var.alarm_cache_cpu_percent }] }
    cache_memory    = { horizontal = [{ label = "Alarme", value = var.alarm_cache_memory_percent }] }
    target_p95      = { horizontal = [{ label = "Alarme (p95)", value = var.alarm_target_p95_seconds }] }
    asg_cpu         = { horizontal = [{ label = "Alvo da escala", value = var.asg_cpu_target }] }
  }

  dashboard_widgets = [
    {
      type   = "alarm"
      x      = 0
      y      = 0
      width  = 24
      height = 4
      properties = {
        title  = "Alarmes (${local.name_prefix})"
        alarms = [for alarm in aws_cloudwatch_metric_alarm.main : alarm.arn]
      }
    },
    {
      type   = "metric"
      x      = 0
      y      = 4
      width  = 12
      height = 6
      properties = {
        title  = "ALB: requisições e respostas 5xx (soma por minuto)"
        region = var.region
        view   = "timeSeries"
        stat   = "Sum"
        period = 60
        metrics = [
          ["AWS/ApplicationELB", "RequestCount", "LoadBalancer", aws_lb.main.arn_suffix, { label = "Requisições" }],
          ["AWS/ApplicationELB", "HTTPCode_ELB_5XX_Count", "LoadBalancer", aws_lb.main.arn_suffix, { label = "5xx do ALB" }],
          ["AWS/ApplicationELB", "HTTPCode_Target_5XX_Count", "LoadBalancer", aws_lb.main.arn_suffix, "TargetGroup", aws_lb_target_group.app.arn_suffix, { label = "5xx das instâncias" }],
        ]
      }
    },
    {
      type   = "metric"
      x      = 12
      y      = 4
      width  = 12
      height = 6
      properties = {
        title       = "Instâncias: tempo de resposta (p50 e p95, em segundos)"
        region      = var.region
        view        = "timeSeries"
        period      = 300
        annotations = local.dashboard_alarm_line.target_p95
        metrics = [
          ["AWS/ApplicationELB", "TargetResponseTime", "LoadBalancer", aws_lb.main.arn_suffix, "TargetGroup", aws_lb_target_group.app.arn_suffix, { stat = "p50", label = "p50" }],
          ["AWS/ApplicationELB", "TargetResponseTime", "LoadBalancer", aws_lb.main.arn_suffix, "TargetGroup", aws_lb_target_group.app.arn_suffix, { stat = "p95", label = "p95" }],
        ]
      }
    },
    {
      type   = "metric"
      x      = 0
      y      = 10
      width  = 12
      height = 6
      properties = {
        title  = "Instâncias: em serviço, desejadas e saúde no ALB"
        region = var.region
        view   = "timeSeries"
        stat   = "Minimum"
        period = 60
        metrics = [
          ["AWS/AutoScaling", "GroupInServiceInstances", "AutoScalingGroupName", aws_autoscaling_group.app.name, { label = "Em serviço" }],
          ["AWS/AutoScaling", "GroupDesiredCapacity", "AutoScalingGroupName", aws_autoscaling_group.app.name, { label = "Desejadas" }],
          ["AWS/ApplicationELB", "HealthyHostCount", "LoadBalancer", aws_lb.main.arn_suffix, "TargetGroup", aws_lb_target_group.app.arn_suffix, { label = "Saudáveis no ALB" }],
          ["AWS/ApplicationELB", "UnHealthyHostCount", "LoadBalancer", aws_lb.main.arn_suffix, "TargetGroup", aws_lb_target_group.app.arn_suffix, { label = "Sem saúde no ALB" }],
        ]
      }
    },
    {
      type   = "metric"
      x      = 12
      y      = 10
      width  = 12
      height = 6
      properties = {
        title       = "Instâncias: CPU média (%)"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.asg_cpu
        metrics = [
          ["AWS/EC2", "CPUUtilization", "AutoScalingGroupName", aws_autoscaling_group.app.name, { label = "CPU média das instâncias" }],
        ]
      }
    },
    {
      type   = "metric"
      x      = 0
      y      = 16
      width  = 6
      height = 6
      properties = {
        title       = "Banco: CPU (%)"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.db_cpu
        metrics     = [["AWS/RDS", "CPUUtilization", "DBInstanceIdentifier", aws_db_instance.barbershop_db.identifier, { label = "CPU" }]]
      }
    },
    {
      type   = "metric"
      x      = 6
      y      = 16
      width  = 6
      height = 6
      properties = {
        title       = "Banco: conexões"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.db_connections
        metrics     = [["AWS/RDS", "DatabaseConnections", "DBInstanceIdentifier", aws_db_instance.barbershop_db.identifier, { label = "Conexões" }]]
      }
    },
    {
      type   = "metric"
      x      = 12
      y      = 16
      width  = 6
      height = 6
      properties = {
        title       = "Banco: espaço livre no disco (bytes)"
        region      = var.region
        view        = "timeSeries"
        stat        = "Minimum"
        period      = 300
        annotations = local.dashboard_alarm_line.db_free_storage
        metrics     = [["AWS/RDS", "FreeStorageSpace", "DBInstanceIdentifier", aws_db_instance.barbershop_db.identifier, { label = "Espaço livre" }]]
      }
    },
    {
      type   = "metric"
      x      = 18
      y      = 16
      width  = 6
      height = 6
      properties = {
        title       = "Banco: memória livre (bytes)"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.db_free_memory
        metrics     = [["AWS/RDS", "FreeableMemory", "DBInstanceIdentifier", aws_db_instance.barbershop_db.identifier, { label = "Memória livre" }]]
      }
    },
    {
      type   = "metric"
      x      = 0
      y      = 22
      width  = 8
      height = 6
      properties = {
        title       = "Cache: CPU (%) do Valkey e da máquina, por nó"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.cache_cpu
        metrics = concat(
          [for node in local.cache_node_ids : ["AWS/ElastiCache", "EngineCPUUtilization", "CacheClusterId", node, { label = "Valkey ${node}" }]],
          [for node in local.cache_node_ids : ["AWS/ElastiCache", "CPUUtilization", "CacheClusterId", node, { label = "Máquina ${node}" }]],
        )
      }
    },
    {
      type   = "metric"
      x      = 8
      y      = 22
      width  = 8
      height = 6
      properties = {
        title       = "Cache: memória usada (% do maxmemory), por nó"
        region      = var.region
        view        = "timeSeries"
        stat        = "Average"
        period      = 60
        annotations = local.dashboard_alarm_line.cache_memory
        metrics     = [for node in local.cache_node_ids : ["AWS/ElastiCache", "DatabaseMemoryUsagePercentage", "CacheClusterId", node, { label = node }]]
      }
    },
    {
      type   = "metric"
      x      = 16
      y      = 22
      width  = 8
      height = 6
      properties = {
        title   = "Cache: evictions (soma em 5 minutos), por nó"
        region  = var.region
        view    = "timeSeries"
        stat    = "Sum"
        period  = 300
        metrics = [for node in local.cache_node_ids : ["AWS/ElastiCache", "Evictions", "CacheClusterId", node, { label = node }]]
      }
    },
  ]
}

# Um dashboard por ambiente, com os números de cada alarme e o estado de todos. As três primeiras
# dashboards da conta não custam nada (docs/nuvem/CUSTOS.md).
resource "aws_cloudwatch_dashboard" "main" {
  dashboard_name = local.name_prefix
  dashboard_body = jsonencode({ widgets = local.dashboard_widgets })
}
