# Testes do CI/CD (role de deploy do GitHub Actions por OIDC, cicd.tf) com o provider AWS
# simulado (mock): rodam sem conta e sem credencial, como os outros. Na pasta terraform/:
# terraform init -backend=false e depois terraform test. O user_data embute o
# ../docker-compose.prod.yml, então a pasta de cima precisa estar à vista (docs/nuvem/TERRAFORM.md).
# O mock devolve o que a configuração pede e inventa só o que a AWS calcularia. Os testes
# conferem a configuração, não o comportamento da AWS nem do GitHub.
#
# try(one([...]), null) == true: verdadeiro só se exatamente um item passar pelo filtro e a
# conferência dele der certo. Nenhum item, ou mais de um, vira falso.
# Listas são comparadas pelo jsonencode(), que não depende do tipo (lista ou tupla).

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

  # O provedor OIDC do GitHub, lido da conta (padrão) ou criado (create_github_oidc_provider).
  mock_data "aws_iam_openid_connect_provider" {
    defaults = {
      arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
    }
  }

  mock_resource "aws_iam_openid_connect_provider" {
    defaults = {
      arn = "arn:aws:iam::123456789012:oidc-provider/token.actions.githubusercontent.com"
    }
  }

  mock_resource "aws_autoscaling_group" {
    defaults = {
      arn = "arn:aws:autoscaling:us-east-1:123456789012:autoScalingGroup:11111111-2222-3333-4444-555555555555:autoScalingGroupName/barberflow-homolog-app"
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

  # Tópico dos alarmes da feat/observabilidade: as ações dos alarmes do CloudWatch precisam de um
  # ARN no formato certo.
  mock_resource "aws_sns_topic" {
    defaults = {
      arn = "arn:aws:sns:us-east-1:123456789012:barberflow-homolog-alarms"
    }
  }
}

# Os runs com a role ligam o create_deploy_role (como o envs/prod.tfvars.example) e usam os nomes
# da homolog, como os outros arquivos de teste. O primeiro run confere o padrão, sem a role.
variables {
  environment = "homolog"
}

# O padrão (create_deploy_role = false), que é o do envs/homolog.tfvars.example: sem role, sem
# política e sem ler o provedor OIDC da conta. O GitHub cria sem proteção o environment que um
# workflow cita e que não existe, então a role só nasce onde o environment já está protegido.
run "default_without_deploy_role" {
  command = apply

  variables {
    github_environment = "homolog"
  }

  assert {
    condition     = length(aws_iam_role.deploy) == 0 && length(aws_iam_role_policy.deploy) == 0
    error_message = "Por padrão (create_deploy_role = false), a role de deploy e a política dela não são criadas."
  }

  assert {
    condition     = length(aws_iam_openid_connect_provider.github) == 0 && length(data.aws_iam_openid_connect_provider.github) == 0
    error_message = "Sem a role de deploy, o provedor OIDC do GitHub não é criado nem lido da conta: a homolog não depende do apply da prod."
  }

  assert {
    condition     = output.deploy_role_arn == null && output.github_environment_variables == null && length(output.github_deploy_subjects) == 0
    error_message = "Sem a role de deploy, as saídas deploy_role_arn e github_environment_variables ficam nulas, e a github_deploy_subjects fica vazia."
  }

  assert {
    condition     = output.image_tag_parameter_name == "/barberflow/homolog/image_tag" && jsonencode(output.ecr_repository_names) == jsonencode({ app = "barberflow-homolog-app", web = "barberflow-homolog-web" })
    error_message = "Sem a role, as saídas do ECR e do parâmetro image_tag continuam, para o deploy à mão."
  }
}

# Com a role: o provedor OIDC vem da conta (o padrão do create_github_oidc_provider) e a role
# aceita só o sub do environment production do josecoene/barber-shop, no formato com os nomes.
run "deploy_role_trust" {
  command = apply

  variables {
    create_deploy_role = true
  }

  assert {
    condition     = length(aws_iam_openid_connect_provider.github) == 0 && length(data.aws_iam_openid_connect_provider.github) == 1 && data.aws_iam_openid_connect_provider.github[0].url == "https://token.actions.githubusercontent.com"
    error_message = "Por padrão (create_github_oidc_provider = false), o provedor OIDC do GitHub é lido da conta, e não criado: a AWS aceita um só por conta."
  }

  assert {
    condition     = length(aws_iam_role.deploy) == 1 && aws_iam_role.deploy[0].name == "barberflow-homolog-deploy" && aws_iam_role.deploy[0].assume_role_policy == local.deploy_assume_role_policy && aws_iam_role.deploy[0].max_session_duration == 3600
    error_message = "A role de deploy barberflow-homolog-deploy usa a trust do local.deploy_assume_role_policy, com sessão de 1 hora."
  }

  # Uma declaração só, com exatamente estes campos: nada de NotPrincipal, NotAction nem outra
  # declaração que abriria a role para outro principal.
  assert {
    condition = length(jsondecode(local.deploy_assume_role_policy).Statement) == 1 && try(one([
      for s in jsondecode(local.deploy_assume_role_policy).Statement :
      jsonencode(keys(s)) == jsonencode(["Action", "Condition", "Effect", "Principal", "Sid"]) &&
      s.Effect == "Allow" &&
      jsonencode(keys(s.Principal)) == jsonencode(["Federated"]) &&
      s.Principal.Federated == data.aws_iam_openid_connect_provider.github[0].arn &&
      s.Action == "sts:AssumeRoleWithWebIdentity"
    ]), null) == true
    error_message = "A trust tem uma declaração só: Allow para o provedor OIDC do GitHub (Federated), com sts:AssumeRoleWithWebIdentity."
  }

  # Só StringEquals, com o aud e o sub e nada mais. O sub é o do environment production do
  # repositório: pull request e job sem o environment têm outro sub e ficam de fora.
  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_assume_role_policy).Statement :
      jsonencode(keys(s.Condition)) == jsonencode(["StringEquals"]) &&
      jsonencode(keys(s.Condition.StringEquals)) == jsonencode(["token.actions.githubusercontent.com:aud", "token.actions.githubusercontent.com:sub"]) &&
      s.Condition.StringEquals["token.actions.githubusercontent.com:aud"] == "sts.amazonaws.com" &&
      jsonencode(flatten([s.Condition.StringEquals["token.actions.githubusercontent.com:sub"]])) == jsonencode(["repo:josecoene/barber-shop:environment:production"])
    ]), null) == true
    error_message = "A trust confere só o aud (sts.amazonaws.com) e o sub repo:josecoene/barber-shop:environment:production, com StringEquals."
  }

  # Nenhum curinga em nenhum valor da condição (o StringEquals não trata * como curinga, mas um
  # * aqui seria sinal de que alguém quis abrir a role para outros repositórios).
  assert {
    condition = alltrue(flatten([
      for s in jsondecode(local.deploy_assume_role_policy).Statement : [
        for v in flatten([for k, values in s.Condition.StringEquals : values]) : !strcontains(v, "*") && !strcontains(v, "?")
      ]
    ]))
    error_message = "Nenhum valor da trust pode ter curinga (* ou ?)."
  }
}

# Repositório criado depois de 15/07/2026 (ou com o formato imutável ligado): com os IDs, a role
# aceita também o sub com o ID do dono e do repositório.
run "immutable_subject" {
  command = apply

  variables {
    create_deploy_role         = true
    github_repository_owner_id = "123456"
    github_repository_id       = "456789"
  }

  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_assume_role_policy).Statement :
      toset(flatten([s.Condition.StringEquals["token.actions.githubusercontent.com:sub"]])) == toset([
        "repo:josecoene/barber-shop:environment:production",
        "repo:josecoene@123456/barber-shop@456789:environment:production",
      ]) && length(flatten([s.Condition.StringEquals["token.actions.githubusercontent.com:sub"]])) == 2
    ]), null) == true
    error_message = "Com os dois IDs, a trust aceita os dois formatos de sub: repo:josecoene/barber-shop:environment:production e repo:josecoene@123456/barber-shop@456789:environment:production."
  }

  assert {
    condition     = toset(output.github_deploy_subjects) == toset(["repo:josecoene/barber-shop:environment:production", "repo:josecoene@123456/barber-shop@456789:environment:production"])
    error_message = "A saída github_deploy_subjects mostra os dois formatos aceitos."
  }
}

# O ambiente que cria o provedor OIDC (o prod, no exemplo): sem thumbprint e só com o aud do STS.
run "creates_oidc_provider_when_asked" {
  command = apply

  variables {
    create_deploy_role          = true
    create_github_oidc_provider = true
  }

  assert {
    condition     = length(aws_iam_openid_connect_provider.github) == 1 && length(data.aws_iam_openid_connect_provider.github) == 0
    error_message = "Com create_github_oidc_provider = true, o provedor OIDC é criado, e o data source não é lido."
  }

  assert {
    condition     = aws_iam_openid_connect_provider.github[0].url == "https://token.actions.githubusercontent.com" && toset(aws_iam_openid_connect_provider.github[0].client_id_list) == toset(["sts.amazonaws.com"])
    error_message = "O provedor OIDC é o do GitHub Actions (https://token.actions.githubusercontent.com), só com o aud sts.amazonaws.com."
  }

  # Sem thumbprint na configuração: a AWS confere o certificado do GitHub pela própria lista de
  # autoridades certificadoras, e um thumbprint escrito aqui só envelheceria (comentário do cicd.tf).
  assert {
    condition     = length(coalesce(aws_iam_openid_connect_provider.github[0].thumbprint_list, [])) == 0
    error_message = "O provedor OIDC do GitHub não leva thumbprint_list: a AWS confere o certificado do GitHub sem ele."
  }

  assert {
    condition     = jsondecode(aws_iam_role.deploy[0].assume_role_policy).Statement[0].Principal.Federated == aws_iam_openid_connect_provider.github[0].arn
    error_message = "A trust aponta para o provedor OIDC criado por este ambiente."
  }
}

run "deploy_role_policy" {
  command = apply

  variables {
    create_deploy_role = true
  }

  assert {
    condition     = length(aws_iam_role_policy.deploy) == 1 && aws_iam_role_policy.deploy[0].role == aws_iam_role.deploy[0].id && aws_iam_role_policy.deploy[0].policy == local.deploy_role_policy && aws_iam_role_policy.deploy[0].name == "barberflow-homolog-deploy"
    error_message = "A política da role de deploy deve ser a do local.deploy_role_policy."
  }

  # A lista exata de ações. Permissão nova entra aqui também, de propósito.
  assert {
    condition = toset(flatten([for s in jsondecode(local.deploy_role_policy).Statement : s.Action])) == toset([
      "autoscaling:DescribeAutoScalingGroups",
      "autoscaling:DescribeInstanceRefreshes",
      "autoscaling:StartInstanceRefresh",
      "ec2:DescribeLaunchTemplates",
      "ecr:BatchCheckLayerAvailability",
      "ecr:BatchGetImage",
      "ecr:CompleteLayerUpload",
      "ecr:DescribeImages",
      "ecr:GetAuthorizationToken",
      "ecr:InitiateLayerUpload",
      "ecr:PutImage",
      "ecr:UploadLayerPart",
      "ssm:GetParameter",
      "ssm:PutParameter",
    ])
    error_message = "A role de deploy tem só as ações do push no ECR, do parâmetro image_tag, do instance refresh e as três consultas de leitura."
  }

  assert {
    condition     = alltrue([for s in jsondecode(local.deploy_role_policy).Statement : s.Effect == "Allow" && jsonencode(keys(s)) == jsonencode(["Action", "Effect", "Resource", "Sid"])])
    error_message = "Toda declaração é um Allow com Action e Resource, sem NotAction, NotResource nem Condition."
  }

  # Nada de iam:PassRole, do state (S3) nem de chave do KMS.
  assert {
    condition     = alltrue([for a in flatten([for s in jsondecode(local.deploy_role_policy).Statement : s.Action]) : !startswith(a, "iam:") && !startswith(a, "s3:") && !startswith(a, "dynamodb:") && !startswith(a, "kms:") && !startswith(a, "sts:")])
    error_message = "A role de deploy não pode ter ação do IAM (iam:PassRole), do S3 (state), do DynamoDB, do KMS nem do STS."
  }

  # Nenhuma ação com curinga, nenhum ARN com curinga e nenhum Resource "*" fora do login no ECR e
  # das três consultas de leitura, que a AWS só aceita com "*".
  assert {
    condition = alltrue([
      for s in jsondecode(local.deploy_role_policy).Statement : alltrue([
        alltrue([for a in flatten([s.Action]) : !strcontains(a, "*")]),
        alltrue([for r in flatten([s.Resource]) : r == "*" || !strcontains(r, "*")]),
        !contains(flatten([s.Resource]), "*") || alltrue([for a in flatten([s.Action]) : contains(["ecr:GetAuthorizationToken", "autoscaling:DescribeAutoScalingGroups", "autoscaling:DescribeInstanceRefreshes", "ec2:DescribeLaunchTemplates"], a)]),
      ])
    ])
    error_message = "Sem curinga nas ações e nos ARNs, e Resource \"*\" só no ecr:GetAuthorizationToken e nas consultas DescribeAutoScalingGroups, DescribeInstanceRefreshes e DescribeLaunchTemplates."
  }

  # O push e a consulta das imagens só nos 2 repositórios do ambiente.
  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_role_policy).Statement :
      toset(flatten([s.Resource])) == toset([for r in aws_ecr_repository.image : r.arn]) && length(flatten([s.Resource])) == 2
      if anytrue([for a in flatten([s.Action]) : startswith(a, "ecr:") && a != "ecr:GetAuthorizationToken"])
    ]), null) == true
    error_message = "As ações do ECR (fora o login) ficam numa declaração só, presa aos 2 repositórios do ambiente (app e web)."
  }

  # O PutParameter só no image_tag: a role não grava nem lê a senha do banco, o APP_KEY nem
  # outro parâmetro.
  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_role_policy).Statement :
      jsonencode(flatten([s.Resource])) == jsonencode([aws_ssm_parameter.operator_managed["image_tag"].arn])
      if anytrue([for a in flatten([s.Action]) : startswith(a, "ssm:")])
    ]), null) == true && aws_ssm_parameter.operator_managed["image_tag"].name == "/barberflow/homolog/image_tag"
    error_message = "As ações do SSM (GetParameter e PutParameter) ficam numa declaração só, presa ao parâmetro /barberflow/homolog/image_tag."
  }

  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_role_policy).Statement :
      jsonencode(flatten([s.Resource])) == jsonencode([aws_autoscaling_group.app.arn])
      if contains(flatten([s.Action]), "autoscaling:StartInstanceRefresh")
    ]), null) == true
    error_message = "O autoscaling:StartInstanceRefresh fica preso ao Auto Scaling do app."
  }

  # Os asserts acima conferem a política própria. Um anexo a mais (ex.: a AdministratorAccess numa
  # aws_iam_role_policy_attachment) ficaria fora deles, e o anexo pode citar a role pela
  # referência, por um local ou pelo nome escrito ("${local.name_prefix}-deploy"). Por isso as
  # duas travas abaixo leem o texto dos .tf da pasta. Exigir o cicd.tf garante que a leitura
  # achou os arquivos.
  #
  # 1. A lista exata dos recursos que ligam política a uma role, qualquer que seja a role e o jeito
  #    de citar a role (os tipos aws_iam_role_polic* e o aws_iam_policy_attachment), nos .tf da
  #    pasta e do bootstrap/ (outra stack, que poderia citar a role pelo nome): a política e o
  #    anexo da role das instâncias (iam.tf) e a política da role de deploy (cicd.tf). Um recurso
  #    novo desses tipos faz o teste falhar; se for de propósito, ele entra nesta lista. O run
  #    iam_role do dados_seguranca.tftest.hcl já recusa, em qualquer .tf da pasta, a
  #    managed_policy_arns e a inline_policy, que põem política dentro da própria role.
  assert {
    condition = contains(fileset(path.module, "*.tf"), "cicd.tf") && jsonencode(sort(flatten([
      for f in concat(tolist(fileset(path.module, "*.tf")), [for b in fileset("${path.module}/bootstrap", "*.tf") : "bootstrap/${b}"]) : [
        for m in regexall("(?m)^\\s*resource\\s+\"?(aws_iam_[a-z_]+)\"?\\s+\"?([A-Za-z0-9_-]+)\"?", file("${path.module}/${f}")) :
        "${m[0]}.${m[1]}" if startswith(m[0], "aws_iam_role_polic") || m[0] == "aws_iam_policy_attachment"
      ]
    ]))) == jsonencode(["aws_iam_role_policy.deploy", "aws_iam_role_policy.ec2", "aws_iam_role_policy_attachment.ssm_core"])
    error_message = "Só três recursos ligam política a uma role: aws_iam_role_policy.deploy (cicd.tf), aws_iam_role_policy.ec2 e aws_iam_role_policy_attachment.ssm_core (iam.tf). Permissão nova do deploy entra no local.deploy_role_policy, e não num anexo; anexo novo a outra role entra nesta lista de propósito."
  }

  # 2. Só uma linha, fora de comentário, liga um recurso à role de deploy pela referência (role,
  #    role_name ou roles com aws_iam_role.deploy na mesma linha): a do aws_iam_role_policy.deploy.
  assert {
    condition     = length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^[^#\\n]*\\b(role|role_name|roles)\\s*=[^#\\n]*\\baws_iam_role\\.deploy\\b", file("${path.module}/${f}"))])) == 1
    error_message = "Só a política própria (aws_iam_role_policy.deploy) pode se ligar à role de deploy. Permissão nova entra no local.deploy_role_policy, e não num anexo."
  }
}

# Os valores do envs/prod.tfvars.example: o prod cria a role e o provedor OIDC e tem os IDs do
# formato imutável. Tudo com o nome do ambiente.
run "prod_deploy_role" {
  command = apply

  variables {
    environment                 = "prod"
    vpc_cidr                    = "10.30.0.0/16"
    create_deploy_role          = true
    create_github_oidc_provider = true
    github_repository_owner_id  = "123456"
    github_repository_id        = "456789"
  }

  assert {
    condition     = aws_iam_role.deploy[0].name == "barberflow-prod-deploy" && aws_iam_role_policy.deploy[0].name == "barberflow-prod-deploy"
    error_message = "Na prod, a role e a política se chamam barberflow-prod-deploy."
  }

  assert {
    condition = try(one([
      for s in jsondecode(local.deploy_role_policy).Statement :
      jsonencode(flatten([s.Resource])) == jsonencode([aws_ssm_parameter.operator_managed["image_tag"].arn])
      if contains(flatten([s.Action]), "ssm:PutParameter")
    ]), null) == true && aws_ssm_parameter.operator_managed["image_tag"].name == "/barberflow/prod/image_tag"
    error_message = "Na prod, o deploy grava só o /barberflow/prod/image_tag."
  }

  assert {
    condition     = toset([for r in aws_ecr_repository.image : r.name]) == toset(["barberflow-prod-app", "barberflow-prod-web"])
    error_message = "Na prod, o deploy usa os repositórios barberflow-prod-app e barberflow-prod-web."
  }

  assert {
    condition     = output.github_environment_variables["ASG_NAME"] == "barberflow-prod-app" && output.github_environment_variables["IMAGE_TAG_PARAMETER"] == "/barberflow/prod/image_tag" && output.github_environment_variables["ECR_APP_REPOSITORY"] == "barberflow-prod-app" && output.github_environment_variables["ECR_WEB_REPOSITORY"] == "barberflow-prod-web"
    error_message = "Na prod, as variáveis do environment apontam para o Auto Scaling, os repositórios e o parâmetro da prod."
  }
}

run "outputs" {
  command = apply

  variables {
    create_deploy_role = true
  }

  assert {
    condition     = output.deploy_role_arn == aws_iam_role.deploy[0].arn && output.image_tag_parameter_name == "/barberflow/homolog/image_tag"
    error_message = "As saídas mostram o ARN da role de deploy e o nome do parâmetro image_tag."
  }

  assert {
    condition     = jsonencode(output.ecr_repository_names) == jsonencode({ app = "barberflow-homolog-app", web = "barberflow-homolog-web" })
    error_message = "A saída ecr_repository_names mostra o nome dos repositórios app e web."
  }

  assert {
    condition     = jsonencode(output.github_deploy_subjects) == jsonencode(["repo:josecoene/barber-shop:environment:production"])
    error_message = "Sem os IDs, a saída github_deploy_subjects mostra só o formato com os nomes."
  }

  # As 7 variáveis do environment production que o deploy.yml lê, com os valores do ambiente.
  assert {
    condition = jsonencode(output.github_environment_variables) == jsonencode({
      APP_URL             = "http://barberflow-homolog-alb-123456789.us-east-1.elb.amazonaws.com"
      ASG_NAME            = "barberflow-homolog-app"
      AWS_DEPLOY_ROLE_ARN = aws_iam_role.deploy[0].arn
      AWS_REGION          = "us-east-1"
      ECR_APP_REPOSITORY  = "barberflow-homolog-app"
      ECR_WEB_REPOSITORY  = "barberflow-homolog-web"
      IMAGE_TAG_PARAMETER = "/barberflow/homolog/image_tag"
    })
    error_message = "A saída github_environment_variables tem as 7 variáveis do deploy.yml: AWS_DEPLOY_ROLE_ARN, AWS_REGION, ASG_NAME, APP_URL, ECR_APP_REPOSITORY, ECR_WEB_REPOSITORY e IMAGE_TAG_PARAMETER."
  }
}

run "rejects_repository_with_wildcard" {
  command = plan

  variables {
    github_repository = "josecoene/*"
  }

  expect_failures = [var.github_repository]
}

run "rejects_repository_without_owner" {
  command = plan

  variables {
    github_repository = "barber-shop"
  }

  expect_failures = [var.github_repository]
}

run "rejects_environment_with_wildcard" {
  command = plan

  variables {
    github_environment = "*"
  }

  expect_failures = [var.github_environment]
}

run "rejects_only_one_github_id" {
  command = plan

  variables {
    github_repository_id = "456789"
  }

  expect_failures = [var.github_repository_id]
}

run "rejects_non_numeric_github_id" {
  command = plan

  variables {
    github_repository_owner_id = "josecoene"
    github_repository_id       = "456789"
  }

  expect_failures = [var.github_repository_owner_id]
}
