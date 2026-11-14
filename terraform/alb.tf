# Balanceador de carga (ALB) do BarberFlow, a porta de entrada pública da aplicação.
#
# - internet-facing, nas subnets públicas, com o security group do ALB (sg.tf: 80 e 443 da
#   internet; saída só para a porta 80 do app);
# - repassa para as instâncias do Auto Scaling (compute.tf) pelo target group, que olha o /up do
#   Laravel. O /up é raso (não consulta banco nem cache): uma queda do banco não faz o Auto
#   Scaling trocar instâncias em loop;
# - HTTPS só com um certificado do ACM (variável certificate_arn): aí a 80 só redireciona (301)
#   para a 443. Sem certificado, a 80 encaminha direto para o app e a 443 não existe;
# - drop_invalid_header_fields: cabeçalho com nome inválido não chega à aplicação;
# - sem access logs, que pediriam um bucket S3 só para eles. O Trivy 0.76.2 não acusa; o
#   .trivyignore.yaml registra o motivo junto dos alertas aceitos do ALB (AWS-0053 e AWS-0054).

locals {
  https_enabled = var.certificate_arn != ""

  # Endereço público da aplicação: o APP_URL do SSM (ssm.tf) e a saída app_url. Sem domínio, o
  # DNS do ALB, em HTTP. Com domínio, o app_url; as variáveis exigem https:// junto com o
  # certificado e recusam um sem o outro.
  app_url = var.app_url != "" ? var.app_url : "http://${aws_lb.main.dns_name}"
}

resource "aws_lb" "main" {
  name               = "${local.name_prefix}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id

  drop_invalid_header_fields = true

  # Com true (prod), nem o terraform destroy apaga o ALB.
  enable_deletion_protection = var.alb_deletion_protection

  tags = {
    Name = "${local.name_prefix}-alb"
  }
}

resource "aws_lb_target_group" "app" {
  name        = "${local.name_prefix}-app"
  port        = 80
  protocol    = "HTTP"
  target_type = "instance"
  vpc_id      = aws_vpc.main.id

  # Numa troca de instância, o ALB espera 30 s as requisições em andamento terminarem (o padrão
  # é 300 s, que só atrasaria o instance refresh).
  deregistration_delay = 30

  # Uma instância entra no ar depois de 2 respostas 200 seguidas (30 s) e sai depois de 3 falhas
  # (45 s). O Auto Scaling troca a instância que sair (health check ELB, compute.tf).
  health_check {
    path                = "/up"
    protocol            = "HTTP"
    port                = "traffic-port"
    matcher             = "200"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "${local.name_prefix}-app"
  }
}

# Porta 80: sem certificado, encaminha para o app; com certificado, só redireciona para o HTTPS.
resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = local.https_enabled ? "redirect" : "forward"
    target_group_arn = local.https_enabled ? null : aws_lb_target_group.app.arn

    dynamic "redirect" {
      for_each = local.https_enabled ? [1] : []

      content {
        port        = "443"
        protocol    = "HTTPS"
        status_code = "HTTP_301"
      }
    }
  }

  tags = {
    Name = "${local.name_prefix}-http"
  }
}

# Porta 443, só com certificado. A política aceita TLS 1.3 e 1.2, nada mais antigo, só com cifras
# de sigilo futuro (Res) e com a troca de chaves híbrida pós-quântica (PQ). É a padrão do console
# da AWS desde 2025; a ELBSecurityPolicy-TLS13-1-2-2021-06 é a anterior, com mais cifras no TLS 1.2.
resource "aws_lb_listener" "https" {
  count = local.https_enabled ? 1 : 0

  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-Res-PQ-2025-09"
  certificate_arn   = var.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }

  tags = {
    Name = "${local.name_prefix}-https"
  }
}
