# Testes da computação (ALB, launch template, user_data, Auto Scaling e orçamento) com o provider
# AWS simulado (mock): rodam sem conta e sem credencial, como os da rede e os dos dados.
# Na pasta terraform/: terraform init -backend=false e depois terraform test. O user_data embute
# o ../docker-compose.prod.yml, então a pasta de cima precisa estar à vista (docs/nuvem/TERRAFORM.md).
# O mock devolve o que a configuração pede e inventa só o que a AWS calcularia. Os testes
# conferem a configuração, não o comportamento da AWS.
# Nenhum assert mostra a senha do RDS: a trava do user_data só pergunta se o texto contém a
# senha (nonsensitive devolve só verdadeiro ou falso), e as travas de texto procuram padrões.

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

  # O provider confere o formato do ARN do ALB e do target group e o lt- do launch template.
  mock_resource "aws_lb" {
    defaults = {
      arn      = "arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/barberflow-homolog-alb/0123456789abcdef"
      dns_name = "barberflow-homolog-alb-123456789.us-east-1.elb.amazonaws.com"
    }
  }

  mock_resource "aws_lb_target_group" {
    defaults = {
      arn = "arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/barberflow-homolog-app/0123456789abcdef"
    }
  }

  mock_resource "aws_launch_template" {
    defaults = {
      id             = "lt-0123456789abcdef0"
      latest_version = 3
    }
  }
}

variables {
  environment = "homolog"
}

# Os padrões das variáveis, que são os do envs/homolog.tfvars.example sem domínio: 1 instância
# (mínimo 1, máximo 2), sem certificado e sem orçamento.
run "homolog_without_domain" {
  command = apply

  # --- Launch template ---

  assert {
    condition     = data.aws_ssm_parameter.al2023_ami.name == "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64" && aws_launch_template.app.image_id == "ami-0123456789abcdef0"
    error_message = "O launch template usa a AMI mais nova do Amazon Linux 2023 x86_64, lida do parâmetro público do SSM."
  }

  assert {
    condition     = aws_launch_template.app.instance_type == "t3.micro" && aws_launch_template.app.name == "barberflow-homolog-app" && aws_launch_template.app.update_default_version == true
    error_message = "O launch template barberflow-homolog-app usa t3.micro por padrão, e cada versão nova vira a padrão."
  }

  # IMDSv2 obrigatório, com hop limit 1: os containers ficam um salto depois do host e não
  # alcançam as credenciais da role.
  assert {
    condition     = aws_launch_template.app.metadata_options[0].http_endpoint == "enabled" && aws_launch_template.app.metadata_options[0].http_tokens == "required" && aws_launch_template.app.metadata_options[0].http_put_response_hop_limit == 1
    error_message = "A instância exige IMDSv2 (http_tokens = required), com hop limit 1."
  }

  assert {
    condition = length(aws_launch_template.app.block_device_mappings) == 1 && alltrue([
      for d in aws_launch_template.app.block_device_mappings :
      d.device_name == "/dev/xvda" && d.ebs[0].volume_type == "gp3" && tostring(d.ebs[0].encrypted) == "true" && d.ebs[0].volume_size == 30 && tostring(d.ebs[0].delete_on_termination) == "true"
    ])
    error_message = "O disco raiz (/dev/xvda) é gp3, criptografado, com 30 GB e apagado junto com a instância."
  }

  assert {
    condition     = aws_launch_template.app.iam_instance_profile[0].name == aws_iam_instance_profile.ec2.name
    error_message = "A instância usa o instance profile da feat/infra-dados-seguranca."
  }

  assert {
    condition = alltrue([
      for t in aws_launch_template.app.tag_specifications : t.tags == tomap(merge(local.tags, { Name = "barberflow-homolog-app" }))
    ]) && toset([for t in aws_launch_template.app.tag_specifications : t.resource_type]) == toset(["instance", "volume"])
    error_message = "Instâncias e discos recebem as tags padrão e o Name barberflow-homolog-app (tag_specifications)."
  }

  # --- Instância sem porta aberta para a internet ---

  # IP público (sem NAT), mas só o security group do app, sem chave SSH.
  assert {
    condition = length(aws_launch_template.app.network_interfaces) == 1 && alltrue([
      for ni in aws_launch_template.app.network_interfaces :
      tostring(ni.associate_public_ip_address) == "true" && toset(ni.security_groups) == toset([aws_security_group.app.id])
    ]) && try(length(aws_launch_template.app.vpc_security_group_ids), 0) == 0 && try(length(aws_launch_template.app.security_group_names), 0) == 0 && aws_launch_template.app.key_name == null
    error_message = "A instância usa só o security group do app (na interface de rede, com IP público) e não tem chave SSH."
  }

  # O security group do app só recebe a 80, só do ALB. O tests/dados_seguranca.tftest.hcl
  # garante que a pasta não tem outra regra de entrada além das 4 conferidas aqui.
  assert {
    condition = try(one([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.ip_protocol == "tcp" && r.from_port == 80 && r.to_port == 80 && r.referenced_security_group_id == aws_security_group.alb.id && r.cidr_ipv4 == null && r.cidr_ipv6 == null if r.security_group_id == aws_security_group.app.id
    ]), null) == true
    error_message = "A instância não pode ter porta aberta para a internet: o security group do app recebe só a 80, só do ALB."
  }

  # --- user_data ---

  # Fim de linha LF: o bash da instância não aceita CRLF (o .gitattributes já pede LF no checkout).
  assert {
    condition     = base64decode(aws_launch_template.app.user_data) == local.user_data && startswith(local.user_data, "#!/bin/bash\n") && strcontains(local.user_data, "\nset -euo pipefail\n") && !strcontains(local.user_data, "\r")
    error_message = "O launch template leva o script do templates/user_data.sh.tftpl, em bash com set -euo pipefail e fim de linha LF."
  }

  # 16 KB antes do base64, o limite da AWS: 21848 caracteres em base64. A folga fica para o
  # docker-compose.prod.yml crescer.
  assert {
    condition     = length(base64encode(local.user_data)) <= 21848
    error_message = "O user_data passou de 16 KB, o limite da AWS."
  }

  assert {
    condition = alltrue([
      strcontains(local.user_data, "readonly SSM_PATH='/barberflow/homolog'"),
      strcontains(local.user_data, "aws ssm get-parameters-by-path --region \"$AWS_REGION_NAME\" --path \"$SSM_PATH\" --recursive --with-decryption"),
      strcontains(local.user_data, "readonly AWS_REGION_NAME='us-east-1'"),
    ])
    error_message = "O user_data lê os parâmetros de /barberflow/homolog do SSM (get-parameters-by-path, recursivo e com --with-decryption)."
  }

  assert {
    condition = alltrue([
      strcontains(local.user_data, "readonly APP_REPOSITORY='${aws_ecr_repository.image["app"].repository_url}'"),
      strcontains(local.user_data, "readonly WEB_REPOSITORY='${aws_ecr_repository.image["web"].repository_url}'"),
      strcontains(local.user_data, "readonly REGISTRY='${split("/", aws_ecr_repository.image["app"].repository_url)[0]}'"),
      strcontains(local.user_data, "readonly IMAGE_TAG_PLACEHOLDER='${local.ssm_operator_parameters["image_tag"].initial_value}'"),
    ])
    error_message = "O user_data monta as imagens com os repositórios do ECR e para enquanto a image_tag for a provisória."
  }

  assert {
    condition = alltrue([
      strcontains(local.user_data, "readonly LOG_GROUP_APP='${aws_cloudwatch_log_group.container["app"].name}'"),
      strcontains(local.user_data, "readonly LOG_GROUP_WEB='${aws_cloudwatch_log_group.container["web"].name}'"),
      strcontains(local.user_data, "\"log-driver\": \"awslogs\""),
      strcontains(local.user_data, "\"awslogs-create-group\": \"false\""),
    ])
    error_message = "O daemon.json manda os logs para o log group app (awslogs, sem criar grupo), e o web recebe o nome do grupo dele."
  }

  # Plugin compose numa versão fixa, conferido pelo SHA-256, e o docker-compose.prod.yml do
  # repositório embutido (gzip e base64).
  assert {
    condition = alltrue([
      can(regex("^v[0-9]+\\.[0-9]+\\.[0-9]+$", local.compose_version)),
      can(regex("^[0-9a-f]{64}$", local.compose_sha256)),
      strcontains(local.user_data, "readonly COMPOSE_VERSION='${local.compose_version}'"),
      strcontains(local.user_data, "readonly COMPOSE_SHA256='${local.compose_sha256}'"),
      strcontains(local.user_data, "sha256sum --check"),
      strcontains(local.user_data, "readonly COMPOSE_FILE_GZIP_BASE64='${base64gzip(file("${path.module}/../docker-compose.prod.yml"))}'"),
    ])
    error_message = "O user_data instala o compose numa versão fixa, conferida pelo SHA-256, e leva o docker-compose.prod.yml do repositório."
  }

  # O SHA-256 do compose embutido fica na tag do launch template e na saída, para o deploy da
  # feat/ci-cd conferir se o compose do commit já foi aplicado (o deploy não leva o compose).
  assert {
    condition     = output.compose_file_sha256 == filesha256("${path.module}/../docker-compose.prod.yml") && aws_launch_template.app.tags["ComposeFileSha256"] == output.compose_file_sha256 && aws_launch_template.app.tags["Name"] == "barberflow-homolog-app"
    error_message = "A tag ComposeFileSha256 do launch template e a saída compose_file_sha256 trazem o SHA-256 do docker-compose.prod.yml que vai no user_data."
  }

  # Nenhum segredo no user_data. O template não cita a senha do RDS (só valores do compute.tf
  # entram nele) e o texto pronto não contém a senha, a chave provisória do Laravel, chave do
  # Laravel (base64:...), chave de acesso da AWS nem chave privada.
  assert {
    condition = alltrue([
      !can(regex("aws_db_instance|DB_PASSWORD|password\\s*=", file("${path.module}/templates/user_data.sh.tftpl"))),
      !can(regex("barbershop_db\\.password", file("${path.module}/compute.tf"))),
      nonsensitive(!strcontains(local.user_data, aws_db_instance.barbershop_db.password)),
      !strcontains(local.user_data, local.ssm_operator_parameters["APP_KEY"].initial_value),
      !can(regex("base64:[A-Za-z0-9+/=]{20,}|AKIA[0-9A-Z]{16}|BEGIN [A-Z ]*PRIVATE KEY", local.user_data)),
    ])
    error_message = "O user_data não pode ter senha nem chave: tudo o que é segredo vem do SSM na subida."
  }

  # --- Auto Scaling ---

  assert {
    condition     = toset(aws_autoscaling_group.app.vpc_zone_identifier) == toset(aws_subnet.public[*].id) && length(distinct(aws_subnet.public[*].availability_zone)) == 2
    error_message = "O Auto Scaling usa as 2 subnets públicas, em 2 zonas."
  }

  assert {
    condition     = aws_autoscaling_group.app.health_check_type == "ELB" && aws_autoscaling_group.app.health_check_grace_period == 300 && toset(aws_autoscaling_group.app.target_group_arns) == toset([aws_lb_target_group.app.arn])
    error_message = "O Auto Scaling usa o health check do ALB (ELB), com 300 s de carência, e põe as instâncias no target group."
  }

  assert {
    condition     = aws_autoscaling_group.app.min_size == 1 && aws_autoscaling_group.app.desired_capacity == 1 && aws_autoscaling_group.app.max_size == 2 && aws_autoscaling_group.app.name == "barberflow-homolog-app"
    error_message = "Por padrão (homolog), o Auto Scaling barberflow-homolog-app tem mínimo 1, desejado 1 e máximo 2."
  }

  # A versão exata do launch template (não $Latest): uma versão nova muda o Auto Scaling, e o
  # Terraform começa o instance refresh.
  assert {
    condition     = aws_autoscaling_group.app.launch_template[0].id == aws_launch_template.app.id && aws_autoscaling_group.app.launch_template[0].version == tostring(aws_launch_template.app.latest_version)
    error_message = "O Auto Scaling usa a versão exata do launch template."
  }

  assert {
    condition     = aws_autoscaling_group.app.instance_refresh[0].strategy == "Rolling" && aws_autoscaling_group.app.instance_refresh[0].preferences[0].min_healthy_percentage == 50
    error_message = "O instance refresh troca as instâncias aos poucos (Rolling), com pelo menos 50% no ar."
  }

  assert {
    condition     = tomap({ for t in aws_autoscaling_group.app.tag : t.key => t.value }) == tomap(merge(local.tags, { Name = "barberflow-homolog-app" })) && alltrue([for t in aws_autoscaling_group.app.tag : t.propagate_at_launch])
    error_message = "O Auto Scaling leva as tags padrão e o Name, passados às instâncias (propagate_at_launch)."
  }

  assert {
    condition     = aws_autoscaling_policy.cpu.autoscaling_group_name == aws_autoscaling_group.app.name && aws_autoscaling_policy.cpu.policy_type == "TargetTrackingScaling" && aws_autoscaling_policy.cpu.target_tracking_configuration[0].predefined_metric_specification[0].predefined_metric_type == "ASGAverageCPUUtilization" && aws_autoscaling_policy.cpu.target_tracking_configuration[0].target_value == 60
    error_message = "O Auto Scaling escala pela CPU média, com alvo de 60% por padrão."
  }

  # --- ALB ---

  assert {
    condition     = aws_lb.main.internal == false && aws_lb.main.load_balancer_type == "application" && toset(aws_lb.main.subnets) == toset(aws_subnet.public[*].id) && toset(aws_lb.main.security_groups) == toset([aws_security_group.alb.id])
    error_message = "O ALB é público (internet-facing), nas subnets públicas, com o security group do ALB."
  }

  assert {
    condition     = aws_lb.main.drop_invalid_header_fields == true && aws_lb.main.enable_deletion_protection == true
    error_message = "O ALB descarta cabeçalhos inválidos e, por padrão, tem proteção contra exclusão."
  }

  assert {
    condition     = aws_lb_target_group.app.port == 80 && aws_lb_target_group.app.protocol == "HTTP" && aws_lb_target_group.app.target_type == "instance" && aws_lb_target_group.app.vpc_id == aws_vpc.main.id && tostring(aws_lb_target_group.app.deregistration_delay) == "30"
    error_message = "O target group manda HTTP para a porta 80 das instâncias e espera 30 s na saída de cada uma."
  }

  # Health check no /up, o raso: não consulta banco nem cache.
  assert {
    condition     = aws_lb_target_group.app.health_check[0].path == "/up" && aws_lb_target_group.app.health_check[0].matcher == "200" && aws_lb_target_group.app.health_check[0].protocol == "HTTP" && aws_lb_target_group.app.health_check[0].port == "traffic-port"
    error_message = "O health check do ALB olha o /up e espera 200."
  }

  # Sem certificado: a 80 encaminha para o app, e a 443 não existe.
  assert {
    condition     = aws_lb_listener.http.port == 80 && aws_lb_listener.http.protocol == "HTTP" && aws_lb_listener.http.default_action[0].type == "forward" && aws_lb_listener.http.default_action[0].target_group_arn == aws_lb_target_group.app.arn && length(aws_lb_listener.http.default_action[0].redirect) == 0
    error_message = "Sem certificado, a porta 80 encaminha para o target group do app."
  }

  assert {
    condition     = length(aws_lb_listener.https) == 0
    error_message = "Sem certificado, não existe a porta 443."
  }

  # --- Parâmetros do SSM ---

  assert {
    condition     = aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value == "http://barberflow-homolog-alb-123456789.us-east-1.elb.amazonaws.com" && output.app_url == aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value
    error_message = "Sem domínio, o APP_URL (e a saída app_url) é o DNS do ALB, em HTTP."
  }

  assert {
    condition     = aws_ssm_parameter.terraform_managed["SESSION_SECURE_COOKIE"].insecure_value == "false" && aws_ssm_parameter.terraform_managed["TRUSTED_PROXIES"].insecure_value == "10.20.0.0/16"
    error_message = "Sem certificado, o cookie de sessão vale também em HTTP; o TRUSTED_PROXIES é a faixa da VPC."
  }

  # --- Orçamento e saídas ---

  assert {
    condition     = length(aws_budgets_budget.monthly) == 0
    error_message = "Sem budget_alert_email, o orçamento não é criado."
  }

  assert {
    condition     = output.alb_dns_name == aws_lb.main.dns_name && output.asg_name == "barberflow-homolog-app" && output.launch_template_id == aws_launch_template.app.id
    error_message = "As saídas mostram o DNS do ALB, o nome do Auto Scaling e o ID do launch template."
  }

  # --- Nenhuma EC2 solta ---

  # Lê o texto dos .tf da pasta e do bootstrap/: nenhuma aws_instance (a EC2 solta da Sprint 1
  # saiu) nem aws_launch_configuration (o jeito antigo). Exigir o compute.tf garante que a
  # leitura achou os arquivos.
  assert {
    condition = contains(fileset(path.module, "*.tf"), "compute.tf") && length(flatten([
      for f in concat(tolist(fileset(path.module, "*.tf")), [for b in fileset("${path.module}/bootstrap", "*.tf") : "bootstrap/${b}"]) :
      regexall("(?m)^\\s*resource\\s+\"(aws_instance|aws_launch_configuration)\"", file("${path.module}/${f}"))
    ])) == 0
    error_message = "Nenhuma aws_instance nem aws_launch_configuration no projeto: as instâncias do app vêm só do Auto Scaling, com o launch template do compute.tf."
  }
}

# Os valores do envs/prod.tfvars.example com domínio, certificado e orçamento.
run "prod_with_domain_and_certificate" {
  command = apply

  variables {
    environment          = "prod"
    vpc_cidr             = "10.30.0.0/16"
    app_url              = "https://barberflow.example.com"
    certificate_arn      = "arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555"
    asg_min_size         = 1
    asg_desired_capacity = 2
    asg_max_size         = 4
    budget_alert_email   = "financeiro@example.com"
  }

  # Com certificado, a 80 só redireciona (301) para a 443.
  assert {
    condition     = aws_lb_listener.http.default_action[0].type == "redirect" && aws_lb_listener.http.default_action[0].target_group_arn == null && aws_lb_listener.http.default_action[0].redirect[0].protocol == "HTTPS" && aws_lb_listener.http.default_action[0].redirect[0].port == "443" && aws_lb_listener.http.default_action[0].redirect[0].status_code == "HTTP_301"
    error_message = "Com certificado, a porta 80 redireciona (301) para o HTTPS na 443."
  }

  assert {
    condition     = length(aws_lb_listener.https) == 1 && aws_lb_listener.https[0].port == 443 && aws_lb_listener.https[0].protocol == "HTTPS" && aws_lb_listener.https[0].certificate_arn == "arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555" && aws_lb_listener.https[0].default_action[0].type == "forward" && aws_lb_listener.https[0].default_action[0].target_group_arn == aws_lb_target_group.app.arn
    error_message = "Com certificado, a porta 443 existe, com o certificado da variável, e encaminha para o app."
  }

  assert {
    condition     = length(aws_lb_listener.https) == 1 && startswith(aws_lb_listener.https[0].ssl_policy, "ELBSecurityPolicy-TLS13-1-2-")
    error_message = "O HTTPS aceita só TLS 1.3 e 1.2 (política ELBSecurityPolicy-TLS13-1-2-...)."
  }

  assert {
    condition     = aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value == "https://barberflow.example.com" && output.app_url == "https://barberflow.example.com" && aws_ssm_parameter.terraform_managed["SESSION_SECURE_COOKIE"].insecure_value == "true"
    error_message = "Com domínio e certificado, o APP_URL é https://<domínio> e o cookie de sessão vale só em HTTPS."
  }

  assert {
    condition     = aws_ssm_parameter.terraform_managed["TRUSTED_PROXIES"].insecure_value == "10.30.0.0/16" && strcontains(local.user_data, "readonly SSM_PATH='/barberflow/prod'") && strcontains(local.user_data, "readonly LOG_GROUP_APP='/barberflow/prod/app'")
    error_message = "Na prod, o TRUSTED_PROXIES é a faixa da VPC da prod, e o user_data lê /barberflow/prod e escreve nos log groups da prod."
  }

  assert {
    condition     = aws_autoscaling_group.app.min_size == 1 && aws_autoscaling_group.app.desired_capacity == 2 && aws_autoscaling_group.app.max_size == 4 && aws_autoscaling_group.app.name == "barberflow-prod-app"
    error_message = "Na prod, o Auto Scaling tem mínimo 1, desejado 2 e máximo 4."
  }

  assert {
    condition = length(aws_budgets_budget.monthly) == 1 && alltrue([
      for b in aws_budgets_budget.monthly :
      b.name == "barberflow-prod-mensal" && b.budget_type == "COST" && b.limit_amount == "30" && b.limit_unit == "USD" && b.time_unit == "MONTHLY"
    ])
    error_message = "Com e-mail, o orçamento mensal de custo (barberflow-prod-mensal) é criado, com limite de US$ 30 por padrão."
  }

  assert {
    condition = length(aws_budgets_budget.monthly) == 1 && toset(flatten([
      for b in aws_budgets_budget.monthly : [for n in b.notification : "${n.notification_type} ${n.comparison_operator} ${n.threshold} ${n.threshold_type}"]
      ])) == toset(["ACTUAL GREATER_THAN 80 PERCENTAGE", "FORECASTED GREATER_THAN 100 PERCENTAGE"]) && alltrue(flatten([
      for b in aws_budgets_budget.monthly : [for n in b.notification : toset(n.subscriber_email_addresses) == toset(["financeiro@example.com"])]
    ]))
    error_message = "O orçamento avisa por e-mail a 80% do gasto real e a 100% do previsto."
  }
}

# Domínio sem HTTPS: o APP_URL segue o app_url, o cookie vale em HTTP e a 80 encaminha.
run "domain_without_certificate" {
  command = apply

  variables {
    app_url = "http://homolog.barberflow.example.com"
  }

  assert {
    condition     = aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value == "http://homolog.barberflow.example.com" && aws_ssm_parameter.terraform_managed["SESSION_SECURE_COOKIE"].insecure_value == "false"
    error_message = "Com domínio e sem certificado, o APP_URL é o app_url e o cookie de sessão vale também em HTTP."
  }

  assert {
    condition     = aws_lb_listener.http.default_action[0].type == "forward" && length(aws_lb_listener.https) == 0
    error_message = "Sem certificado, a 80 encaminha para o app e a 443 não existe."
  }
}

# Primeiro deploy: o Auto Scaling nasce sem instância, enquanto o ECR não tem imagem.
run "first_deploy_without_instances" {
  command = apply

  variables {
    asg_min_size         = 0
    asg_desired_capacity = 0
  }

  assert {
    condition     = aws_autoscaling_group.app.min_size == 0 && aws_autoscaling_group.app.desired_capacity == 0 && aws_autoscaling_group.app.max_size == 2
    error_message = "O mínimo e o desejado aceitam 0, para o primeiro apply antes de existir imagem no ECR."
  }
}

run "rejects_certificate_without_domain" {
  command = plan

  variables {
    certificate_arn = "arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555"
  }

  expect_failures = [var.app_url]
}

run "rejects_certificate_with_http_domain" {
  command = plan

  variables {
    app_url         = "http://barberflow.example.com"
    certificate_arn = "arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555"
  }

  expect_failures = [var.app_url]
}

run "rejects_https_domain_without_certificate" {
  command = plan

  variables {
    app_url = "https://barberflow.example.com"
  }

  expect_failures = [var.app_url]
}

run "rejects_invalid_certificate_arn" {
  command = plan

  variables {
    app_url         = "https://barberflow.example.com"
    certificate_arn = "barberflow-certificado"
  }

  expect_failures = [var.certificate_arn]
}

run "rejects_certificate_from_another_region" {
  command = plan

  variables {
    app_url         = "https://barberflow.example.com"
    certificate_arn = "arn:aws:acm:sa-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555"
  }

  expect_failures = [var.certificate_arn]
}

# A AMI e as imagens Docker são x86_64.
run "rejects_graviton_instance_type" {
  command = plan

  variables {
    instance_type = "t4g.micro"
  }

  expect_failures = [var.instance_type]
}

run "rejects_small_root_volume" {
  command = plan

  variables {
    root_volume_size = 8
  }

  expect_failures = [var.root_volume_size]
}

run "rejects_desired_above_max" {
  command = plan

  variables {
    asg_min_size         = 1
    asg_desired_capacity = 3
    asg_max_size         = 2
  }

  expect_failures = [var.asg_desired_capacity]
}

run "rejects_desired_below_min" {
  command = plan

  variables {
    asg_min_size         = 2
    asg_desired_capacity = 1
    asg_max_size         = 2
  }

  expect_failures = [var.asg_desired_capacity]
}

run "rejects_max_zero" {
  command = plan

  variables {
    asg_min_size         = 0
    asg_desired_capacity = 0
    asg_max_size         = 0
  }

  expect_failures = [var.asg_max_size]
}

run "rejects_max_above_ten" {
  command = plan

  variables {
    asg_max_size = 11
  }

  expect_failures = [var.asg_max_size]
}

run "rejects_cpu_target_out_of_range" {
  command = plan

  variables {
    asg_cpu_target = 95
  }

  expect_failures = [var.asg_cpu_target]
}

run "rejects_invalid_budget_email" {
  command = plan

  variables {
    budget_alert_email = "financeiro"
  }

  expect_failures = [var.budget_alert_email]
}

run "rejects_budget_limit_zero" {
  command = plan

  variables {
    budget_limit_usd = 0
  }

  expect_failures = [var.budget_limit_usd]
}
