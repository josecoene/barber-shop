# Role das instâncias do app (EC2 do Auto Scaling, na feat/infra-computacao), com o mínimo:
#   - Session Manager para entrar na instância, sem SSH e sem porta 22
#     (política da AWS AmazonSSMManagedInstanceCore);
#   - pull só nos 2 repositórios do ECR (ecr.tf);
#   - escrita só nos 2 log groups dos containers (logs.tf);
#   - leitura só dos parâmetros /barberflow/<ambiente>/ do SSM (ssm.tf), com o kms:Decrypt
#     da chave padrão do SSM, e nada dos parâmetros de outro ambiente.
#
# As políticas ficam em jsonencode() nos locals, e não num data "aws_iam_policy_document":
# o provider simulado não monta o JSON do data source, e o teste precisa ler a política.

# Os ARNs usam a partição aws, a das regiões comerciais (a variável region). Um data
# "aws_partition" aqui quebraria os testes da rede, cujo provider simulado inventa a partição.

data "aws_caller_identity" "current" {}

# Chave padrão do SSM, a que cifra os SecureString criados sem key_id (DB_PASSWORD e APP_KEY).
# A AWS só cria essa chave no primeiro uso; a consulta (DescribeKey) já cria, então o plan
# funciona numa conta nova.
data "aws_kms_alias" "ssm" {
  name = "alias/aws/ssm"
}

locals {
  # ARN do caminho dos parâmetros do ambiente, ex.: arn:aws:ssm:us-east-1:<conta>:parameter/barberflow/homolog
  ssm_path_arn = "arn:aws:ssm:${var.region}:${data.aws_caller_identity.current.account_id}:parameter${local.ssm_path}"

  # O get-parameters-by-path é autorizado pelo ARN do próprio caminho (sem /* no fim), e o
  # get-parameter pelo ARN de cada parâmetro (com /*). Por isso entram os dois.
  ssm_parameter_arns = [local.ssm_path_arn, "${local.ssm_path_arn}/*"]

  ec2_assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "Ec2AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ec2.amazonaws.com"
        }
        Action = "sts:AssumeRole"
      },
    ]
  })

  ec2_role_policy = jsonencode({
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
        Sid      = "EcrPullOnlyBarberFlowImages"
        Effect   = "Allow"
        Action   = ["ecr:BatchCheckLayerAvailability", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer"]
        Resource = [for repository in aws_ecr_repository.image : repository.arn]
      },
      {
        # O nome de cada stream é o Docker que cria; o curinga fica preso a cada log group.
        Sid      = "WriteContainerLogs"
        Effect   = "Allow"
        Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
        Resource = flatten([for group in aws_cloudwatch_log_group.container : [group.arn, "${group.arn}:log-stream:*"]])
      },
      {
        Sid      = "ReadEnvironmentParameters"
        Effect   = "Allow"
        Action   = ["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath"]
        Resource = local.ssm_parameter_arns
      },
      {
        # Decifrar os SecureString, só quando o pedido vem do próprio SSM. A política da chave
        # aws/ssm já libera esse uso para a conta; esta linha deixa a permissão escrita.
        Sid      = "DecryptSecureStringsThroughSsm"
        Effect   = "Allow"
        Action   = "kms:Decrypt"
        Resource = data.aws_kms_alias.ssm.target_key_arn
        Condition = {
          StringEquals = {
            "kms:ViaService" = "ssm.${var.region}.amazonaws.com"
          }
        }
      },
      {
        # A AmazonSSMManagedInstanceCore libera ssm:GetParameter e ssm:GetParameters em qualquer
        # parâmetro da conta. Sem esta negação, uma instância da homolog leria a senha do banco
        # da prod. O Deny vale mais que qualquer Allow.
        Sid         = "DenyParametersOutsideEnvironment"
        Effect      = "Deny"
        Action      = ["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath", "ssm:GetParameterHistory"]
        NotResource = local.ssm_parameter_arns
      },
    ]
  })
}

resource "aws_iam_role" "ec2" {
  name               = "${local.name_prefix}-ec2"
  description        = "Instancias do app do BarberFlow: ECR, CloudWatch Logs, SSM Parameter Store e Session Manager"
  assume_role_policy = local.ec2_assume_role_policy

  tags = {
    Name = "${local.name_prefix}-ec2"
  }
}

resource "aws_iam_role_policy" "ec2" {
  name   = "${local.name_prefix}-ec2"
  role   = aws_iam_role.ec2.id
  policy = local.ec2_role_policy
}

resource "aws_iam_role_policy_attachment" "ssm_core" {
  role       = aws_iam_role.ec2.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# O launch template da feat/infra-computacao usa este perfil (iam_instance_profile).
resource "aws_iam_instance_profile" "ec2" {
  name = "${local.name_prefix}-ec2"
  role = aws_iam_role.ec2.name

  tags = {
    Name = "${local.name_prefix}-ec2"
  }
}
