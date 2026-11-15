# Role do deploy pelo GitHub Actions (.github/workflows/deploy.yml), sem chave de acesso guardada
# no GitHub: o job do deploy, no environment production do repositório, pede um token OIDC ao
# GitHub e troca o token por credenciais desta role (sts:AssumeRoleWithWebIdentity), que valem
# 1 hora. Passo a passo no docs/nuvem/CI-CD.md.
#
# - A role só existe com create_deploy_role = true (o prod, no exemplo). A trust confere o
#   environment do GitHub, mas quem protege o environment é o próprio GitHub: quando um workflow
#   cita um environment que ainda não existe, o GitHub cria esse environment sem revisor e sem
#   regra de branch, e um workflow de qualquer branch assumiria a role. Por isso o apply que cria
#   a role vem depois do environment configurado, com revisores e branches (docs/nuvem/CI-CD.md,
#   "Configurar o GitHub"), e o ambiente que não tem esse environment (a homolog) fica sem role.
# - Provedor OIDC token.actions.githubusercontent.com: a AWS aceita um só por conta. Com
#   create_github_oidc_provider = true, este ambiente cria; com false (padrão), lê o que existe,
#   e só quando a role é criada.
#   Sem thumbprint: para o GitHub, a AWS confere o certificado pela própria lista de autoridades
#   certificadoras e ignora o thumbprint, que o provider 6.73 deixa opcional (documentação da
#   AWS e do provider conferidas em 13/11/2026).
# - Trust: só o aud sts.amazonaws.com e o sub do environment do repositório, com StringEquals e
#   sem curinga. Aceita os dois formatos de sub da documentação do GitHub: o com os nomes
#   (repo:dono/repositório:environment:production) e o imutável, com os IDs do dono e do
#   repositório (repo:dono@ID/repositório@ID:environment:production), quando os IDs estão nas
#   variáveis. Pull request e job sem o environment não entram: o sub deles é outro. Um job com
#   o environment manda o mesmo sub de qualquer branch, e quem barra a branch errada é a regra
#   do environment no GitHub (primeiro item).
# - Permissões mínimas: push e consulta das imagens só nos 2 repositórios do ECR; leitura e
#   escrita só no parâmetro image_tag do ambiente; instance refresh só no Auto Scaling do app;
#   e três consultas só de leitura que a AWS aceita apenas com Resource "*". Nada de
#   iam:PassRole, do state (S3) nem dos outros parâmetros do SSM (senha do banco e APP_KEY).
#
# As políticas ficam em jsonencode() nos locals, e não num data "aws_iam_policy_document": o
# provider simulado não monta o JSON do data source, e o tests/cicd.tftest.hcl precisa ler.

locals {
  github_oidc_url = "https://token.actions.githubusercontent.com"

  # Nulo quando o ambiente não cria o provedor nem a role (nada lê o provedor da conta).
  github_oidc_provider_arn = var.create_github_oidc_provider ? aws_iam_openid_connect_provider.github[0].arn : one(data.aws_iam_openid_connect_provider.github[*].arn)

  # Os dois formatos de sub de um job que usa o environment (docs.github.com, "OpenID Connect
  # reference", conferido em 13/11/2026). O imutável só entra com os dois IDs preenchidos.
  github_deploy_subjects = concat(
    ["repo:${var.github_repository}:environment:${var.github_environment}"],
    var.github_repository_id == "" ? [] : [
      format(
        "repo:%s@%s/%s@%s:environment:%s",
        split("/", var.github_repository)[0], var.github_repository_owner_id,
        split("/", var.github_repository)[1], var.github_repository_id,
        var.github_environment,
      ),
    ],
  )

  deploy_assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "GitHubActionsDeployEnvironment"
        Effect = "Allow"
        Principal = {
          Federated = local.github_oidc_provider_arn
        }
        Action = "sts:AssumeRoleWithWebIdentity"
        Condition = {
          StringEquals = {
            "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
            "token.actions.githubusercontent.com:sub" = local.github_deploy_subjects
          }
        }
      },
    ]
  })

  # Consultas do deploy que a AWS não restringe por recurso (só aceitam Resource "*"). São só
  # leitura: o Auto Scaling (launch template e capacidade), o andamento do instance refresh e a
  # tag ComposeFileSha256 do launch template.
  deploy_read_only_actions = [
    "autoscaling:DescribeAutoScalingGroups",
    "autoscaling:DescribeInstanceRefreshes",
    "ec2:DescribeLaunchTemplates",
  ]

  deploy_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        # Login no ECR (docker login). Esta ação não aceita outro Resource além de "*".
        Sid      = "EcrLogin"
        Effect   = "Allow"
        Action   = "ecr:GetAuthorizationToken"
        Resource = "*"
      },
      {
        # As 6 ações do push da documentação do ECR, mais o DescribeImages, que confere se a tag
        # já está no repositório (rollback e deploy repetido). Só nos 2 repositórios do ambiente.
        Sid    = "EcrPushOnlyBarberFlowImages"
        Effect = "Allow"
        Action = [
          "ecr:BatchCheckLayerAvailability",
          "ecr:BatchGetImage",
          "ecr:CompleteLayerUpload",
          "ecr:DescribeImages",
          "ecr:InitiateLayerUpload",
          "ecr:PutImage",
          "ecr:UploadLayerPart",
        ]
        Resource = [for repository in aws_ecr_repository.image : repository.arn]
      },
      {
        # A tag no ar: o deploy lê a anterior (para o aviso de rollback) e grava a nova. Só este
        # parâmetro, que é String: nada de kms:Decrypt.
        Sid      = "WriteImageTagParameter"
        Effect   = "Allow"
        Action   = ["ssm:GetParameter", "ssm:PutParameter"]
        Resource = aws_ssm_parameter.operator_managed["image_tag"].arn
      },
      {
        Sid      = "RefreshAppInstances"
        Effect   = "Allow"
        Action   = "autoscaling:StartInstanceRefresh"
        Resource = aws_autoscaling_group.app.arn
      },
      {
        Sid      = "ReadDeployTargets"
        Effect   = "Allow"
        Action   = local.deploy_read_only_actions
        Resource = "*"
      },
    ]
  })
}

# Um provedor por conta: só o ambiente com create_github_oidc_provider = true cria.
resource "aws_iam_openid_connect_provider" "github" {
  count = var.create_github_oidc_provider ? 1 : 0

  url            = local.github_oidc_url
  client_id_list = ["sts.amazonaws.com"]

  tags = {
    Name = "github-actions"
  }
}

# Num ambiente com a role que não cria o provedor, o que já existe na conta. Sem ele, o plan para
# com "reading IAM OIDC Provider (...): couldn't find resource": aplique antes o ambiente que cria
# (docs/nuvem/CI-CD.md). Sem a role, nada é lido.
data "aws_iam_openid_connect_provider" "github" {
  count = var.create_deploy_role && !var.create_github_oidc_provider ? 1 : 0

  url = local.github_oidc_url
}

resource "aws_iam_role" "deploy" {
  count = var.create_deploy_role ? 1 : 0

  name               = "${local.name_prefix}-deploy"
  description        = "Deploy pelo GitHub Actions (OIDC): push no ECR, image_tag no SSM e instance refresh"
  assume_role_policy = local.deploy_assume_role_policy

  # 1 hora, o padrão. O deploy.yml pede credenciais novas antes de esperar o instance refresh.
  max_session_duration = 3600

  tags = {
    Name = "${local.name_prefix}-deploy"
  }
}

resource "aws_iam_role_policy" "deploy" {
  count = var.create_deploy_role ? 1 : 0

  name   = "${local.name_prefix}-deploy"
  role   = aws_iam_role.deploy[0].id
  policy = local.deploy_role_policy
}
