# Testes da observabilidade (monitoring.tf: tópico SNS, alarmes do CloudWatch e dashboard) com o
# provider AWS simulado (mock): rodam sem conta e sem credencial, como os outros. O user_data
# embute o ../docker-compose.prod.yml, então a pasta de cima precisa estar à vista
# (docs/nuvem/TERRAFORM.md, "Validar e testar sem conta da AWS").
# O mock devolve o que a configuração pede e inventa só o que a AWS calcularia. Os testes
# conferem a configuração, não o comportamento da AWS.

mock_provider "aws" {
  mock_data "aws_availability_zones" {
    defaults = {
      names = ["us-east-1a", "us-east-1b", "us-east-1c"]
    }
  }

  mock_data "aws_caller_identity" {
    defaults = {
      account_id = "123456789012"
    }
  }

  mock_data "aws_kms_alias" {
    defaults = {
      target_key_arn = "arn:aws:kms:us-east-1:123456789012:key/11111111-2222-3333-4444-555555555555"
    }
  }

  # AMI que o parâmetro público do Amazon Linux 2023 devolveria.
  mock_data "aws_ssm_parameter" {
    defaults = {
      insecure_value = "ami-0123456789abcdef0"
    }
  }

  # O provider confere o formato do ARN do ALB, do target group e do tópico (nas ações dos
  # alarmes) e o lt- do launch template. O arn_suffix é o que as métricas do ALB usam.
  mock_resource "aws_lb" {
    defaults = {
      arn        = "arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/barberflow-homolog-alb/0123456789abcdef"
      arn_suffix = "app/barberflow-homolog-alb/0123456789abcdef"
      dns_name   = "barberflow-homolog-alb-123456789.us-east-1.elb.amazonaws.com"
    }
  }

  mock_resource "aws_lb_target_group" {
    defaults = {
      arn        = "arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/barberflow-homolog-app/0123456789abcdef"
      arn_suffix = "targetgroup/barberflow-homolog-app/0123456789abcdef"
    }
  }

  mock_resource "aws_launch_template" {
    defaults = {
      id             = "lt-0123456789abcdef0"
      latest_version = 3
    }
  }

  mock_resource "aws_sns_topic" {
    defaults = {
      arn = "arn:aws:sns:us-east-1:123456789012:barberflow-homolog-alarms"
    }
  }
}

variables {
  environment = "homolog"
}

# Os padrões das variáveis, que são os do envs/homolog.tfvars.example: um nó de cache, mínimo de
# 1 instância e sem e-mail.
run "homolog_defaults" {
  command = apply

  # --- Tópico SNS ---

  assert {
    condition     = aws_sns_topic.alarms.name == "barberflow-homolog-alarms" && output.alarm_topic_arn == aws_sns_topic.alarms.arn
    error_message = "O tópico dos alarmes se chama barberflow-homolog-alarms, e a saída alarm_topic_arn mostra o ARN dele."
  }

  assert {
    condition     = length(aws_sns_topic_subscription.alarm_email) == 0
    error_message = "Sem alarm_email, o tópico não tem assinatura nenhuma."
  }

  # --- Alarmes: a lista exata ---

  assert {
    condition = toset(keys(aws_cloudwatch_metric_alarm.main)) == toset([
      "alb-5xx", "target-5xx", "target-p95", "target-unhealthy", "asg-in-service",
      "db-cpu", "db-free-storage", "db-free-memory", "db-connections",
      "cache-001-cpu", "cache-001-memory", "cache-001-evictions",
    ])
    error_message = "A homolog tem 12 alarmes: 4 do ALB, 1 do Auto Scaling, 4 do RDS e 3 do único nó do cache."
  }

  assert {
    condition     = alltrue([for k, alarm in aws_cloudwatch_metric_alarm.main : alarm.alarm_name == "barberflow-homolog-${k}"]) && output.alarm_names == sort([for alarm in aws_cloudwatch_metric_alarm.main : alarm.alarm_name])
    error_message = "Cada alarme se chama barberflow-homolog-<chave>, e a saída alarm_names lista todos em ordem."
  }

  # --- Ações dos alarmes no tópico ---

  assert {
    condition = alltrue([
      for alarm in aws_cloudwatch_metric_alarm.main :
      toset(alarm.alarm_actions) == toset([aws_sns_topic.alarms.arn]) && toset(alarm.ok_actions) == toset([aws_sns_topic.alarms.arn]) && length(coalesce(alarm.insufficient_data_actions, [])) == 0 && alarm.actions_enabled == true
    ])
    error_message = "Todo alarme avisa no tópico do ambiente quando dispara e quando volta ao normal, e só nele."
  }

  # --- Cada alarme no recurso certo ---

  assert {
    condition = alltrue([
      for k in ["alb-5xx"] :
      aws_cloudwatch_metric_alarm.main[k].dimensions == tomap({ LoadBalancer = aws_lb.main.arn_suffix })
    ])
    error_message = "O alarme de 5xx do ALB olha o ALB do ambiente (arn_suffix)."
  }

  assert {
    condition = alltrue([
      for k in ["target-5xx", "target-p95", "target-unhealthy"] :
      aws_cloudwatch_metric_alarm.main[k].dimensions == tomap({ LoadBalancer = aws_lb.main.arn_suffix, TargetGroup = aws_lb_target_group.app.arn_suffix })
    ])
    error_message = "Os alarmes das instâncias olham o ALB e o target group do app."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.main["asg-in-service"].dimensions == tomap({ AutoScalingGroupName = aws_autoscaling_group.app.name }) && aws_autoscaling_group.app.name == "barberflow-homolog-app" && contains(aws_autoscaling_group.app.enabled_metrics, "GroupInServiceInstances")
    error_message = "O alarme do Auto Scaling olha o grupo do app, que publica o GroupInServiceInstances (compute.tf)."
  }

  assert {
    condition = alltrue([
      for k in ["db-cpu", "db-free-storage", "db-free-memory", "db-connections"] :
      aws_cloudwatch_metric_alarm.main[k].dimensions == tomap({ DBInstanceIdentifier = aws_db_instance.barbershop_db.identifier })
    ]) && aws_db_instance.barbershop_db.identifier == "barberflow-homolog-db"
    error_message = "Os alarmes do RDS olham o banco do ambiente (barberflow-homolog-db)."
  }

  # O nó do cache tem o nome do replication group com -001 no fim (cluster mode desligado).
  assert {
    condition = alltrue([
      for k in ["cache-001-cpu", "cache-001-memory", "cache-001-evictions"] :
      aws_cloudwatch_metric_alarm.main[k].dimensions == tomap({ CacheClusterId = "${aws_elasticache_replication_group.cache.replication_group_id}-001" })
    ]) && aws_elasticache_replication_group.cache.replication_group_id == "barberflow-homolog-cache"
    error_message = "Os alarmes do cache olham o nó barberflow-homolog-cache-001, o primeiro do replication group."
  }

  # Métrica, estatística, período, pontos, comparação e falta de dado de cada alarme.
  assert {
    condition = alltrue([
      for k, esperado in {
        "alb-5xx"             = "AWS/ApplicationELB HTTPCode_ELB_5XX_Count Sum 300 1/1 GreaterThanThreshold notBreaching"
        "target-5xx"          = "AWS/ApplicationELB HTTPCode_Target_5XX_Count Sum 300 1/1 GreaterThanThreshold notBreaching"
        "target-p95"          = "AWS/ApplicationELB TargetResponseTime p95 300 3/3 GreaterThanThreshold notBreaching"
        "target-unhealthy"    = "AWS/ApplicationELB UnHealthyHostCount Minimum 60 5/5 GreaterThanThreshold notBreaching"
        "asg-in-service"      = "AWS/AutoScaling GroupInServiceInstances Minimum 60 5/5 LessThanThreshold breaching"
        "db-cpu"              = "AWS/RDS CPUUtilization Average 60 5/5 GreaterThanThreshold missing"
        "db-free-storage"     = "AWS/RDS FreeStorageSpace Minimum 60 5/5 LessThanThreshold missing"
        "db-free-memory"      = "AWS/RDS FreeableMemory Average 60 15/15 LessThanThreshold missing"
        "db-connections"      = "AWS/RDS DatabaseConnections Average 60 5/5 GreaterThanThreshold missing"
        "cache-001-cpu"       = "AWS/ElastiCache EngineCPUUtilization Average 60 5/5 GreaterThanThreshold missing"
        "cache-001-memory"    = "AWS/ElastiCache DatabaseMemoryUsagePercentage Average 60 5/5 GreaterThanThreshold missing"
        "cache-001-evictions" = "AWS/ElastiCache Evictions Sum 300 1/1 GreaterThanThreshold missing"
      } :
      "${aws_cloudwatch_metric_alarm.main[k].namespace} ${aws_cloudwatch_metric_alarm.main[k].metric_name} ${coalesce(aws_cloudwatch_metric_alarm.main[k].statistic, aws_cloudwatch_metric_alarm.main[k].extended_statistic)} ${aws_cloudwatch_metric_alarm.main[k].period} ${aws_cloudwatch_metric_alarm.main[k].datapoints_to_alarm}/${aws_cloudwatch_metric_alarm.main[k].evaluation_periods} ${aws_cloudwatch_metric_alarm.main[k].comparison_operator} ${aws_cloudwatch_metric_alarm.main[k].treat_missing_data}" == esperado
    ])
    error_message = "Cada alarme olha a métrica certa, com a estatística, o período, os pontos, a comparação e o tratamento de falta de dado da tabela do docs/nuvem/TERRAFORM.md."
  }

  # Estatística comum ou percentil, nunca os dois.
  assert {
    condition = alltrue([
      for alarm in aws_cloudwatch_metric_alarm.main :
      (alarm.statistic == null) != (alarm.extended_statistic == null)
    ])
    error_message = "Cada alarme usa uma estatística comum (statistic) ou um percentil (extended_statistic), nunca os dois."
  }

  # Limites padrão das variáveis.
  assert {
    condition = alltrue([
      for k, esperado in {
        "alb-5xx"             = 10
        "target-5xx"          = 10
        "target-p95"          = 0.5
        "target-unhealthy"    = 0
        "asg-in-service"      = 1
        "db-cpu"              = 80
        "db-free-storage"     = 2 * 1024 * 1024 * 1024
        "db-free-memory"      = 100 * 1024 * 1024
        "db-connections"      = 50
        "cache-001-cpu"       = 90
        "cache-001-memory"    = 80
        "cache-001-evictions" = 0
      } :
      aws_cloudwatch_metric_alarm.main[k].threshold == esperado
    ])
    error_message = "Os limites padrão: 10 respostas 5xx, p95 de 0,5 s, nenhuma instância sem saúde, o mínimo do Auto Scaling, CPU do banco 80%, 2 GB e 100 MB livres, 50 conexões, CPU do Valkey 90%, memória 80% e nenhuma eviction."
  }

  # --- Dashboard ---

  assert {
    condition     = aws_cloudwatch_dashboard.main.dashboard_name == "barberflow-homolog" && output.cloudwatch_dashboard_url == "https://us-east-1.console.aws.amazon.com/cloudwatch/home?region=us-east-1#dashboards/dashboard/barberflow-homolog"
    error_message = "O dashboard se chama barberflow-homolog, e a saída cloudwatch_dashboard_url aponta para ele no console."
  }

  # O quadro de alarmes lista todos os alarmes do ambiente, e só eles.
  assert {
    condition = toset(one([
      for w in jsondecode(aws_cloudwatch_dashboard.main.dashboard_body).widgets : w.properties.alarms if w.type == "alarm"
    ])) == toset([for alarm in aws_cloudwatch_metric_alarm.main : alarm.arn])
    error_message = "O dashboard tem um quadro com o estado de todos os alarmes do ambiente."
  }

  # Os gráficos ficam na região do ambiente e olham os mesmos recursos dos alarmes.
  assert {
    condition = alltrue([
      for w in jsondecode(aws_cloudwatch_dashboard.main.dashboard_body).widgets : w.properties.region == "us-east-1" if w.type == "metric"
      ]) && alltrue([
      for texto in [aws_lb.main.arn_suffix, aws_lb_target_group.app.arn_suffix, aws_autoscaling_group.app.name, aws_db_instance.barbershop_db.identifier, "barberflow-homolog-cache-001"] :
      strcontains(aws_cloudwatch_dashboard.main.dashboard_body, jsonencode(texto))
    ])
    error_message = "Os gráficos do dashboard ficam na região do ambiente e mostram o ALB, o target group, o Auto Scaling, o banco e o nó do cache."
  }

  # --- Travas que leem o texto dos .tf ---

  # Todo alarme da pasta é deste recurso, que o teste confere inteiro: um alarme novo fora dele
  # escaparia da conferência das ações no tópico.
  assert {
    condition = contains(fileset(path.module, "*.tf"), "monitoring.tf") && jsonencode(flatten([
      for f in fileset(path.module, "*.tf") : [for m in regexall("(?m)^\\s*resource\\s+\"(aws_cloudwatch_metric_alarm|aws_cloudwatch_composite_alarm)\"\\s+\"([A-Za-z0-9_-]+)\"", file("${path.module}/${f}")) : "${f} ${m[0]} ${m[1]}"]
    ])) == jsonencode(["monitoring.tf aws_cloudwatch_metric_alarm main"])
    error_message = "Os alarmes ficam todos no aws_cloudwatch_metric_alarm.main do monitoring.tf. Alarme novo entra no local.alarms."
  }

  # SNS só com e-mail: a única assinatura da pasta é a do monitoring.tf, e o protocolo dela é
  # email (run prod_with_email).
  assert {
    condition = jsonencode(flatten([
      for f in concat(tolist(fileset(path.module, "*.tf")), [for b in fileset("${path.module}/bootstrap", "*.tf") : "bootstrap/${b}"]) :
      [for m in regexall("(?m)^\\s*resource\\s+\"(aws_sns_topic_subscription|aws_sns_topic)\"\\s+\"([A-Za-z0-9_-]+)\"", file("${path.module}/${f}")) : "${f} ${m[0]} ${m[1]}"]
    ])) == jsonencode(["monitoring.tf aws_sns_topic alarms", "monitoring.tf aws_sns_topic_subscription alarm_email"]) && can(regex("(?m)^\\s*protocol\\s*=\\s*\"email\"\\s*$", file("${path.module}/monitoring.tf"))) && length(regexall("(?m)^\\s*protocol\\s*=", file("${path.module}/monitoring.tf"))) == 1
    error_message = "O único tópico SNS e a única assinatura ficam no monitoring.tf, e a assinatura é por e-mail."
  }
}

# Os valores do envs/prod.tfvars.example: cache com réplica (2 nós), 2 instâncias desejadas e o
# e-mail dos alarmes.
run "prod_with_email" {
  command = apply

  variables {
    environment              = "prod"
    vpc_cidr                 = "10.30.0.0/16"
    cache_num_cache_clusters = 2
    cache_automatic_failover = true
    asg_min_size             = 1
    asg_desired_capacity     = 2
    asg_max_size             = 4
    alarm_email              = "alertas@example.com"
  }

  assert {
    condition = length(aws_sns_topic_subscription.alarm_email) == 1 && alltrue([
      for s in aws_sns_topic_subscription.alarm_email :
      s.protocol == "email" && s.endpoint == "alertas@example.com" && s.topic_arn == aws_sns_topic.alarms.arn
    ])
    error_message = "Com alarm_email, o tópico ganha uma assinatura por e-mail com o endereço da variável."
  }

  assert {
    condition     = aws_sns_topic.alarms.name == "barberflow-prod-alarms" && aws_cloudwatch_dashboard.main.dashboard_name == "barberflow-prod"
    error_message = "Na prod, o tópico e o dashboard levam o nome da prod."
  }

  # Dois nós de cache: três alarmes para cada um.
  assert {
    condition = length(aws_cloudwatch_metric_alarm.main) == 15 && alltrue([
      for k in ["cache-001-cpu", "cache-002-cpu", "cache-001-memory", "cache-002-memory", "cache-001-evictions", "cache-002-evictions"] :
      contains(keys(aws_cloudwatch_metric_alarm.main), k)
    ]) && aws_cloudwatch_metric_alarm.main["cache-002-cpu"].dimensions == tomap({ CacheClusterId = "barberflow-prod-cache-002" }) && aws_cloudwatch_metric_alarm.main["cache-001-evictions"].dimensions == tomap({ CacheClusterId = "barberflow-prod-cache-001" })
    error_message = "Com 2 nós de cache, a prod tem 15 alarmes, três para cada nó (barberflow-prod-cache-001 e -002)."
  }

  assert {
    condition     = alltrue([for k, alarm in aws_cloudwatch_metric_alarm.main : alarm.alarm_name == "barberflow-prod-${k}" && toset(alarm.alarm_actions) == toset([aws_sns_topic.alarms.arn])]) && aws_cloudwatch_metric_alarm.main["db-cpu"].dimensions == tomap({ DBInstanceIdentifier = "barberflow-prod-db" })
    error_message = "Na prod, os alarmes levam o nome da prod, olham o banco da prod e avisam no tópico da prod."
  }

  assert {
    condition = alltrue([
      for node in ["barberflow-prod-cache-001", "barberflow-prod-cache-002"] :
      strcontains(aws_cloudwatch_dashboard.main.dashboard_body, jsonencode(node))
    ])
    error_message = "O dashboard da prod mostra os dois nós do cache."
  }
}

# Limites trocados pelas variáveis.
run "custom_thresholds" {
  command = apply

  variables {
    alarm_http_5xx_threshold        = 25
    alarm_target_p95_seconds        = 1.5
    alarm_unhealthy_hosts_threshold = 1
    alarm_db_cpu_percent            = 70
    alarm_db_free_storage_gb        = 5
    alarm_db_free_memory_mb         = 200
    alarm_db_connections            = 40
    alarm_cache_cpu_percent         = 75
    alarm_cache_memory_percent      = 60
    alarm_cache_evictions           = 100
    asg_min_size                    = 1
    asg_desired_capacity            = 2
    asg_max_size                    = 3
  }

  assert {
    condition = alltrue([
      for k, esperado in {
        "alb-5xx"             = 25
        "target-5xx"          = 25
        "target-p95"          = 1.5
        "target-unhealthy"    = 1
        "asg-in-service"      = 1
        "db-cpu"              = 70
        "db-free-storage"     = 5 * 1024 * 1024 * 1024
        "db-free-memory"      = 200 * 1024 * 1024
        "db-connections"      = 40
        "cache-001-cpu"       = 75
        "cache-001-memory"    = 60
        "cache-001-evictions" = 100
      } :
      aws_cloudwatch_metric_alarm.main[k].threshold == esperado
    ])
    error_message = "Cada limite segue a sua variável (o do Auto Scaling, o asg_min_size)."
  }

  assert {
    condition     = strcontains(aws_cloudwatch_metric_alarm.main["target-p95"].alarm_description, "1,5 s") && strcontains(aws_cloudwatch_metric_alarm.main["db-free-storage"].alarm_description, "5 GB")
    error_message = "A descrição de cada alarme, que vai no e-mail, mostra o limite em uso."
  }
}

# Primeiro deploy: com mínimo 0, o alarme do Auto Scaling não dispara pelo número de instâncias.
run "first_deploy_min_zero" {
  command = apply

  variables {
    asg_min_size         = 0
    asg_desired_capacity = 0
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.main["asg-in-service"].threshold == 0
    error_message = "Com asg_min_size 0, o limite do alarme de instâncias em serviço é 0."
  }
}

run "rejects_invalid_alarm_email" {
  command = plan

  variables {
    alarm_email = "alertas"
  }

  expect_failures = [var.alarm_email]
}

run "rejects_negative_5xx_threshold" {
  command = plan

  variables {
    alarm_http_5xx_threshold = -1
  }

  expect_failures = [var.alarm_http_5xx_threshold]
}

run "rejects_p95_zero" {
  command = plan

  variables {
    alarm_target_p95_seconds = 0
  }

  expect_failures = [var.alarm_target_p95_seconds]
}

run "rejects_db_cpu_above_100" {
  command = plan

  variables {
    alarm_db_cpu_percent = 150
  }

  expect_failures = [var.alarm_db_cpu_percent]
}

# O disco do banco tem 20 GB (main.tf): um limite de 20 GB livres deixaria o alarme sempre
# disparado.
run "rejects_free_storage_not_below_disk" {
  command = plan

  variables {
    alarm_db_free_storage_gb = 20
  }

  expect_failures = [aws_cloudwatch_metric_alarm.main]
}

run "rejects_cache_memory_zero" {
  command = plan

  variables {
    alarm_cache_memory_percent = 0
  }

  expect_failures = [var.alarm_cache_memory_percent]
}

run "rejects_fractional_evictions" {
  command = plan

  variables {
    alarm_cache_evictions = 0.5
  }

  expect_failures = [var.alarm_cache_evictions]
}

run "rejects_db_connections_zero" {
  command = plan

  variables {
    alarm_db_connections = 0
  }

  expect_failures = [var.alarm_db_connections]
}
