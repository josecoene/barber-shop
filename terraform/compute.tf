# Instâncias do app: launch template e Auto Scaling, no lugar da EC2 solta da Sprint 1.
#
# - Amazon Linux 2023 (x86_64), com a AMI mais nova lida de um parâmetro público do SSM;
# - nas subnets públicas, com IP público e sem NAT Gateway (custo por hora). Nenhuma porta fica
#   aberta para a internet: o security group do app só aceita a 80 vinda do ALB (sg.tf), e o
#   acesso à instância é pelo Session Manager, sem SSH;
# - IMDSv2 obrigatório com hop limit 1: só o host lê os metadados da instância. Os containers
#   ficam um salto depois e não alcançam as credenciais da role (nenhum deles precisa delas);
# - disco gp3 criptografado;
# - o user_data (templates/user_data.sh.tftpl) instala o Docker, monta o .env com o SSM e sobe o
#   docker-compose.prod.yml. Nenhum segredo vai no user_data nem no launch template;
# - Auto Scaling em 2 zonas, com o health check do ALB (/up), troca de instâncias por instance
#   refresh e escala pela CPU.

# AMI mais nova do Amazon Linux 2023, parâmetro público mantido pela AWS. Cada plan lê de novo:
# quando a AWS publica uma AMI nova, o launch template ganha uma versão nova no apply seguinte, e
# o Auto Scaling troca as instâncias (instance refresh).
data "aws_ssm_parameter" "al2023_ami" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}

locals {
  # Plugin Docker Compose que o user_data instala (o AL2023 não traz no dnf). O SHA-256 é o do
  # binário docker-compose-linux-x86_64 no checksums.txt da release, conferido em 13/11/2026.
  # Para atualizar, troque os dois juntos (docs/nuvem/TERRAFORM.md, "Atualizar versões").
  compose_version = "v5.7.1"
  compose_sha256  = "af426722a8af5de812fcee30cc1c39f0639f5f723cbadefb25e5d4db054064cf"

  # O docker-compose.prod.yml vai dentro do user_data, compactado: mudou o arquivo, o launch
  # template ganha versão nova e o Auto Scaling troca as instâncias.
  user_data = templatefile("${path.module}/templates/user_data.sh.tftpl", {
    region                   = var.region
    ssm_path                 = local.ssm_path
    registry                 = split("/", aws_ecr_repository.image["app"].repository_url)[0]
    app_repository_url       = aws_ecr_repository.image["app"].repository_url
    web_repository_url       = aws_ecr_repository.image["web"].repository_url
    log_group_app            = aws_cloudwatch_log_group.container["app"].name
    log_group_web            = aws_cloudwatch_log_group.container["web"].name
    image_tag_placeholder    = local.ssm_operator_parameters["image_tag"].initial_value
    compose_version          = local.compose_version
    compose_sha256           = local.compose_sha256
    compose_file_gzip_base64 = base64gzip(file("${path.module}/../docker-compose.prod.yml"))
  })

  # SHA-256 desse docker-compose.prod.yml. Só o apply leva uma mudança nele às instâncias: o
  # deploy da feat/ci-cd troca a imagem, não o compose. O deploy compara este valor (tag
  # ComposeFileSha256 do launch template, abaixo) com o do commit e para se forem diferentes.
  compose_file_sha256 = filesha256("${path.module}/../docker-compose.prod.yml")

  # Nome e tags das instâncias e dos discos. O default_tags do provider não chega a eles.
  app_instance_tags = merge(local.tags, { Name = "${local.name_prefix}-app" })
}

resource "aws_launch_template" "app" {
  name        = "${local.name_prefix}-app"
  description = "Instancias do app do BarberFlow: Amazon Linux 2023 com Docker"

  image_id      = data.aws_ssm_parameter.al2023_ami.insecure_value
  instance_type = var.instance_type

  # Cada mudança vira a versão padrão, que o Auto Scaling usa.
  update_default_version = true

  iam_instance_profile {
    name = aws_iam_instance_profile.ec2.name
  }

  # IP público (as subnets não dão sozinhas) para falar com ECR, SSM, CloudWatch, dnf e GitHub
  # sem NAT Gateway. Só o security group do app, que só recebe do ALB.
  network_interfaces {
    device_index                = 0
    associate_public_ip_address = true
    security_groups             = [aws_security_group.app.id]
    delete_on_termination       = true
  }

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
    instance_metadata_tags      = "disabled"
  }

  # Disco raiz (/dev/xvda no Amazon Linux 2023): sistema, Docker e imagens. Os logs vão para o
  # CloudWatch, e o banco e o cache ficam fora da instância.
  block_device_mappings {
    device_name = "/dev/xvda"

    ebs {
      volume_size           = var.root_volume_size
      volume_type           = "gp3"
      encrypted             = true
      delete_on_termination = true
    }
  }

  user_data = base64encode(local.user_data)

  tag_specifications {
    resource_type = "instance"
    tags          = local.app_instance_tags
  }

  tag_specifications {
    resource_type = "volume"
    tags          = local.app_instance_tags
  }

  # A tag ComposeFileSha256 mostra qual compose as instâncias novas recebem, para o deploy
  # conferir sem ler o state (aws ec2 describe-launch-templates). Mudar a tag não cria versão
  # nova do launch template; o compose novo, sim.
  tags = {
    Name              = "${local.name_prefix}-app"
    ComposeFileSha256 = local.compose_file_sha256
  }

  lifecycle {
    # A AWS aceita até 16 KB de user_data antes do base64 (21848 caracteres depois dele). O
    # length() conta letras, não bytes, por isso a conta é feita no texto em base64.
    precondition {
      condition     = length(base64encode(local.user_data)) <= 21848
      error_message = "O user_data passou de 16 KB, o limite da AWS. Encurte o templates/user_data.sh.tftpl ou o docker-compose.prod.yml."
    }
  }
}

resource "aws_autoscaling_group" "app" {
  name = "${local.name_prefix}-app"

  # Subnets públicas, uma por zona: com az_count = 2, duas zonas.
  vpc_zone_identifier = aws_subnet.public[*].id

  # Capacidade por variável (envs/<ambiente>.tfvars). O primeiro deploy começa com 0, enquanto
  # não há imagem no ECR (docs/nuvem/TERRAFORM.md, "Primeiro deploy").
  min_size         = var.asg_min_size
  desired_capacity = var.asg_desired_capacity
  max_size         = var.asg_max_size

  # A versão exata (e não $Latest): uma versão nova do launch template muda o Auto Scaling, e o
  # Terraform começa o instance refresh sozinho.
  launch_template {
    id      = aws_launch_template.app.id
    version = aws_launch_template.app.latest_version
  }

  # As instâncias entram no target group do ALB, e o Auto Scaling troca a que o ALB der como
  # doente no /up. Os primeiros 300 s depois de subir não contam: é o tempo do user_data
  # (Docker, imagens, migrations).
  target_group_arns         = [aws_lb_target_group.app.arn]
  health_check_type         = "ELB"
  health_check_grace_period = 300

  # Instância nova só entra na média de CPU (escala) e no instance refresh depois de 300 s.
  default_instance_warmup = 300

  # Troca as instâncias aos poucos, deixando pelo menos metade no ar. Com o desejado em 1 (a
  # homolog), a única instância sai antes da nova ficar pronta, e o app fica fora do ar alguns
  # minutos; na prod (2), troca uma de cada vez.
  instance_refresh {
    strategy = "Rolling"

    preferences {
      min_healthy_percentage = 50
    }
  }

  # Métricas do grupo no CloudWatch, a cada minuto e sem custo, para os alarmes e o dashboard
  # da feat/observabilidade.
  enabled_metrics = [
    "GroupDesiredCapacity",
    "GroupInServiceInstances",
    "GroupMaxSize",
    "GroupMinSize",
    "GroupPendingInstances",
    "GroupTerminatingInstances",
    "GroupTotalInstances",
  ]
  metrics_granularity = "1Minute"

  # O default_tags do provider não chega ao Auto Scaling: as tags vão uma a uma, também para as
  # instâncias que ele cria (propagate_at_launch).
  dynamic "tag" {
    for_each = local.app_instance_tags

    content {
      key                 = tag.key
      value               = tag.value
      propagate_at_launch = true
    }
  }
}

# Escala pela CPU média das instâncias: acima do alvo, cria instância (até o máximo); abaixo,
# remove (até o mínimo).
resource "aws_autoscaling_policy" "cpu" {
  name                   = "${local.name_prefix}-cpu"
  autoscaling_group_name = aws_autoscaling_group.app.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }

    target_value = var.asg_cpu_target
  }
}
