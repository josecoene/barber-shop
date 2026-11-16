# Testes de dados e segurança (security groups, IAM, ECR, logs, RDS, cache e parâmetros do
# SSM) com o provider AWS simulado (mock): rodam sem conta e sem credencial, como os da rede.
# Na pasta terraform/: terraform init -backend=false e depois terraform test.
# O mock devolve o que a configuração pede e inventa só o que a AWS calcularia (IDs, ARNs,
# endereços). Por isso os testes conferem a configuração, não o comportamento da AWS.
# Nenhum assert compara ou mostra a senha do RDS. As travas que leem o texto dos .tf só
# procuram padrões (regexall), e a da senha pula o main.tf, o único lugar em que ela está.
#
# try(one([...]), null) == true: verdadeiro só se exatamente um item passar pelo filtro e a
# conferência dele der certo. Nenhum item, ou mais de um, vira falso.

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

  # Recursos da feat/infra-computacao cujo ID ou ARN outro recurso recebe: o provider confere o
  # formato (ARN do ALB e do target group, lt- no launch template), e o texto que o mock inventa
  # não passaria.
  mock_resource "aws_lb" {
    defaults = {
      arn = "arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/barberflow-homolog-alb/0123456789abcdef"
    }
  }

  mock_resource "aws_lb_target_group" {
    defaults = {
      arn = "arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/barberflow-homolog-app/0123456789abcdef"
    }
  }

  mock_resource "aws_launch_template" {
    defaults = {
      id = "lt-0123456789abcdef0"
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

# Só o ambiente: cada run põe os valores do .tfvars.example de que precisa, e o
# run defaults_without_domain confere os valores padrão das variáveis.
variables {
  environment = "homolog"
}

run "security_groups" {
  command = apply

  # Os asserts seguintes leem pelo plano só o que está nas listas deste run: os 4 security
  # groups (alb, app, db e cache); as regras de entrada alb_from_internet (4), app_from_alb,
  # db_from_app e cache_from_app; e as de saída alb_to_app, app_to_internet (https e smtp),
  # app_to_db e app_to_cache. O que ficar fora das listas fica fora dos asserts. Por isso esta
  # trava, que lê o texto dos .tf da pasta, exige a contagem exata: 4 recursos
  # aws_security_group, 4 de regra de entrada e 4 de regra de saída. Security group ou regra
  # nova entra também nas listas deste run. Exigir o sg.tf garante que a leitura achou os arquivos.
  assert {
    condition = contains(fileset(path.module, "*.tf"), "sg.tf") && alltrue([
      for kind, want in { aws_security_group = 4, aws_vpc_security_group_ingress_rule = 4, aws_vpc_security_group_egress_rule = 4 } :
      length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^\\s*resource\\s+\"${kind}\"", file("${path.module}/${f}"))])) == want
    ])
    error_message = "A pasta deve ter 4 recursos aws_security_group, 4 aws_vpc_security_group_ingress_rule e 4 aws_vpc_security_group_egress_rule, os do sg.tf. Security group ou regra nova entra também nas listas deste teste."
  }

  # Regra escrita dentro do security group também ficaria fora dos asserts: o bloco ingress { },
  # o dynamic "ingress" e o atributo ingress = [{ ... }] (e o mesmo para egress), além do
  # aws_security_group_rule antigo. O security group padrão da VPC (network.tf) usa
  # ingress = [] e egress = [], listas vazias, o único jeito aceito: em cada arquivo, toda
  # linha "ingress =" ou "egress =" tem de ser uma lista vazia.
  assert {
    condition = alltrue([
      for f in fileset(path.module, "*.tf") :
      length(regexall("(?m)^\\s*(resource\\s+\"aws_security_group_rule\"|(ingress|egress)\\s*\\{|dynamic\\s+\"(ingress|egress)\")", file("${path.module}/${f}"))) == 0 &&
      length(regexall("(?m)^\\s*(ingress|egress)\\s*=", file("${path.module}/${f}"))) == length(regexall("(?m)^\\s*(ingress|egress)\\s*=\\s*\\[\\s*\\]", file("${path.module}/${f}")))
    ])
    error_message = "Nada de aws_security_group_rule nem de regra dentro de um security group (bloco ingress { }, dynamic \"ingress\" ou ingress = [{ ... }], e o mesmo para egress): a regra ficaria fora dos testes. Dentro do security group, só a lista vazia (ingress = [])."
  }

  # A mesma coisa pelo plano, nos 4 security groups: nenhuma regra dentro deles.
  assert {
    condition     = alltrue([for sg in [aws_security_group.alb, aws_security_group.app, aws_security_group.db, aws_security_group.cache] : length(sg.ingress) == 0 && length(sg.egress) == 0])
    error_message = "Os 4 security groups não podem ter regra dentro deles (ingress ou egress): cada regra é um recurso aws_vpc_security_group_ingress_rule ou _egress_rule do sg.tf."
  }

  assert {
    condition     = alltrue([for sg in [aws_security_group.alb, aws_security_group.app, aws_security_group.db, aws_security_group.cache] : sg.vpc_id == aws_vpc.main.id])
    error_message = "Os 4 security groups devem ficar na VPC do projeto."
  }

  # Nenhuma entrada da internet fora do ALB.
  assert {
    condition = alltrue([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.security_group_id == aws_security_group.alb.id if r.cidr_ipv4 == "0.0.0.0/0" || r.cidr_ipv6 == "::/0"
    ])
    error_message = "Só o security group do ALB pode receber da internet (0.0.0.0/0 ou ::/0)."
  }

  assert {
    condition = alltrue([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.cidr_ipv4 == null && r.cidr_ipv6 == null && r.prefix_list_id == null && r.referenced_security_group_id != null if r.security_group_id != aws_security_group.alb.id
    ])
    error_message = "Fora do ALB, toda entrada vem de outro security group, nunca de uma faixa de IP."
  }

  assert {
    condition = toset([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      "${r.ip_protocol} ${r.from_port}-${r.to_port} ${coalesce(r.cidr_ipv4, r.cidr_ipv6)}" if r.security_group_id == aws_security_group.alb.id
    ]) == toset(["tcp 80-80 0.0.0.0/0", "tcp 80-80 ::/0", "tcp 443-443 0.0.0.0/0", "tcp 443-443 ::/0"])
    error_message = "O ALB recebe exatamente 80 e 443 da internet, em IPv4 e IPv6."
  }

  assert {
    condition = try(one([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.ip_protocol == "tcp" && r.from_port == 80 && r.to_port == 80 && r.referenced_security_group_id == aws_security_group.alb.id if r.security_group_id == aws_security_group.app.id
    ]), null) == true
    error_message = "O app recebe só a porta 80, só do security group do ALB."
  }

  assert {
    condition = try(one([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.ip_protocol == "tcp" && r.from_port == 3306 && r.to_port == 3306 && r.referenced_security_group_id == aws_security_group.app.id if r.security_group_id == aws_security_group.db.id
    ]), null) == true
    error_message = "O banco recebe só a porta 3306, só do security group do app."
  }

  assert {
    condition = try(one([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.ip_protocol == "tcp" && r.from_port == 6379 && r.to_port == 6379 && r.referenced_security_group_id == aws_security_group.app.id if r.security_group_id == aws_security_group.cache.id
    ]), null) == true
    error_message = "O cache recebe só a porta 6379, só do security group do app."
  }

  assert {
    condition = alltrue([
      for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) :
      r.from_port > 22 || r.to_port < 22
    ])
    error_message = "Nenhuma entrada na porta 22: o acesso às instâncias é pelo Session Manager."
  }

  assert {
    condition     = length([for r in concat(values(aws_vpc_security_group_egress_rule.app_to_internet), [aws_vpc_security_group_egress_rule.alb_to_app, aws_vpc_security_group_egress_rule.app_to_db, aws_vpc_security_group_egress_rule.app_to_cache]) : r if contains([aws_security_group.db.id, aws_security_group.cache.id], r.security_group_id)]) == 0
    error_message = "Banco e cache não têm regra de saída."
  }

  assert {
    condition = try(one([
      for r in concat(values(aws_vpc_security_group_egress_rule.app_to_internet), [aws_vpc_security_group_egress_rule.alb_to_app, aws_vpc_security_group_egress_rule.app_to_db, aws_vpc_security_group_egress_rule.app_to_cache]) :
      r.ip_protocol == "tcp" && r.from_port == 80 && r.to_port == 80 && r.referenced_security_group_id == aws_security_group.app.id && r.cidr_ipv4 == null && r.cidr_ipv6 == null if r.security_group_id == aws_security_group.alb.id
    ]), null) == true
    error_message = "O ALB só sai para o security group do app, na porta 80."
  }

  # A saída do app vai porta a porta, nunca com todos os protocolos (-1, que não leva porta):
  # 443 e a porta do SMTP (587, o padrão da variável mail_port) para a internet, porque não há
  # NAT nem VPC endpoints; 3306 só para o banco e 6379 só para o cache.
  assert {
    condition = toset([
      for r in concat(values(aws_vpc_security_group_egress_rule.app_to_internet), [aws_vpc_security_group_egress_rule.alb_to_app, aws_vpc_security_group_egress_rule.app_to_db, aws_vpc_security_group_egress_rule.app_to_cache]) :
      "${r.ip_protocol} ${coalesce(r.from_port, -1)}-${coalesce(r.to_port, -1)} ${coalesce(r.cidr_ipv4, r.cidr_ipv6, r.prefix_list_id, r.referenced_security_group_id)}" if r.security_group_id == aws_security_group.app.id
    ]) == toset(["tcp 443-443 0.0.0.0/0", "tcp 587-587 0.0.0.0/0", "tcp 3306-3306 ${aws_security_group.db.id}", "tcp 6379-6379 ${aws_security_group.cache.id}"])
    error_message = "O app sai só na 443 (HTTPS) e na porta do SMTP (587) para a internet, na 3306 só para o banco e na 6379 só para o cache."
  }

  # A AWS recusa acento e alguns símbolos na descrição do security group e da regra; o erro
  # só apareceria no apply de verdade.
  assert {
    condition = alltrue([
      for d in concat(
        [for sg in [aws_security_group.alb, aws_security_group.app, aws_security_group.db, aws_security_group.cache] : sg.description],
        [for r in concat(values(aws_vpc_security_group_ingress_rule.alb_from_internet), [aws_vpc_security_group_ingress_rule.app_from_alb, aws_vpc_security_group_ingress_rule.db_from_app, aws_vpc_security_group_ingress_rule.cache_from_app]) : r.description],
        [for r in concat(values(aws_vpc_security_group_egress_rule.app_to_internet), [aws_vpc_security_group_egress_rule.alb_to_app, aws_vpc_security_group_egress_rule.app_to_db, aws_vpc_security_group_egress_rule.app_to_cache]) : r.description],
      ) : can(regex("^[a-zA-Z0-9 ._:/()#,@\\[\\]+=&;{}!$*-]+$", d))
    ])
    error_message = "Toda regra e todo security group precisam de descrição, só com os caracteres que a AWS aceita (sem acento)."
  }
}

run "iam_role" {
  command = apply

  assert {
    condition     = aws_iam_role.ec2.assume_role_policy == local.ec2_assume_role_policy && try(one([for s in jsondecode(local.ec2_assume_role_policy).Statement : s.Effect == "Allow" && s.Principal.Service == "ec2.amazonaws.com" && s.Action == "sts:AssumeRole"]), null) == true
    error_message = "Só o serviço EC2 assume a role (sts:AssumeRole)."
  }

  assert {
    condition     = aws_iam_instance_profile.ec2.role == aws_iam_role.ec2.name && aws_iam_instance_profile.ec2.name == "barberflow-homolog-ec2"
    error_message = "O instance profile deve usar a role da EC2."
  }

  assert {
    condition     = aws_iam_role_policy_attachment.ssm_core.role == aws_iam_role.ec2.name && aws_iam_role_policy_attachment.ssm_core.policy_arn == "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
    error_message = "A role precisa da AmazonSSMManagedInstanceCore, para o Session Manager (sem SSH)."
  }

  assert {
    condition     = aws_iam_role_policy.ec2.role == aws_iam_role.ec2.id && aws_iam_role_policy.ec2.policy == local.ec2_role_policy
    error_message = "A política própria da role deve ser a do local.ec2_role_policy."
  }

  # Os asserts deste run conferem os 3 recursos ligados à role: a política própria, o anexo da
  # AmazonSSMManagedInstanceCore e o instance profile. Um anexo a mais (ex.: AdministratorAccess)
  # ficaria fora deles. Por isso esta trava lê o texto dos .tf da pasta: só 3 linhas, fora de
  # comentário, citam aws_iam_role.ec2, e nenhum recurso usa managed_policy_arns nem
  # inline_policy, que põem política dentro da própria role (os dois estão obsoletos no
  # provider, e ler o atributo pelo plano daria aviso). Recurso novo ligado à role entra
  # também neste run. Exigir o iam.tf garante que a leitura achou os arquivos.
  assert {
    condition     = contains(fileset(path.module, "*.tf"), "iam.tf") && length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^[^#\\n]*aws_iam_role\\.ec2\\.", file("${path.module}/${f}"))])) == 3 && length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^\\s*(managed_policy_arns|inline_policy)\\b", file("${path.module}/${f}"))])) == 0
    error_message = "Só a política própria (aws_iam_role_policy.ec2), o anexo da AmazonSSMManagedInstanceCore e o instance profile podem citar aws_iam_role.ec2, e a role não pode ter managed_policy_arns nem inline_policy. Permissão nova entra no local.ec2_role_policy; recurso novo ligado à role entra também neste teste."
  }

  # Nenhuma Action "*" ou "serviço:*", e nenhum Resource "*" fora do ecr:GetAuthorizationToken.
  assert {
    condition = alltrue([
      for s in jsondecode(local.ec2_role_policy).Statement : alltrue([
        alltrue([for a in flatten([s.Action]) : a != "*" && !endswith(a, ":*")]),
        !contains(flatten([try(s.Resource, [])]), "*") || alltrue([for a in flatten([s.Action]) : a == "ecr:GetAuthorizationToken"]),
      ])
    ])
    error_message = "A política da role não pode ter Action com curinga nem Resource \"*\", fora do ecr:GetAuthorizationToken (que só aceita \"*\")."
  }

  # Todo Resource com curinga fica preso ao caminho do SSM do ambiente ou aos streams dos 2 log groups.
  assert {
    condition = alltrue([
      for r in flatten([for s in jsondecode(local.ec2_role_policy).Statement : try(s.Resource, []) if s.Effect == "Allow"]) :
      r == "*" || !strcontains(r, "*") || r == "arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/homolog/*" || anytrue([for g in aws_cloudwatch_log_group.container : r == "${g.arn}:log-stream:*"])
    ])
    error_message = "Curinga no Resource só no caminho /barberflow/homolog/ do SSM e nos streams dos log groups app e web."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Allow" && s.Resource == "*" if s.Action == "ecr:GetAuthorizationToken"]), null) == true
    error_message = "A role precisa do ecr:GetAuthorizationToken para o docker login no ECR."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Allow" && toset(s.Action) == toset(["ecr:BatchCheckLayerAvailability", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer"]) && toset(s.Resource) == toset([for r in aws_ecr_repository.image : r.arn]) if s.Sid == "EcrPullOnlyBarberFlowImages"]), null) == true
    error_message = "O pull no ECR vale só para os 2 repositórios do projeto, sem push."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Allow" && toset(s.Action) == toset(["logs:CreateLogStream", "logs:PutLogEvents"]) && toset(s.Resource) == toset(flatten([for g in aws_cloudwatch_log_group.container : [g.arn, "${g.arn}:log-stream:*"]])) if s.Sid == "WriteContainerLogs"]), null) == true
    error_message = "A escrita de logs vale só para os log groups app e web, sem criar nem apagar grupo."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Allow" && toset(s.Action) == toset(["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath"]) && toset(s.Resource) == toset(["arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/homolog", "arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/homolog/*"]) if s.Sid == "ReadEnvironmentParameters"]), null) == true
    error_message = "A leitura do SSM vale só para /barberflow/homolog e o que está dentro dele."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Allow" && s.Action == "kms:Decrypt" && s.Resource == "arn:aws:kms:us-east-1:123456789012:key/11111111-2222-3333-4444-555555555555" && s.Condition.StringEquals["kms:ViaService"] == "ssm.us-east-1.amazonaws.com" if s.Sid == "DecryptSecureStringsThroughSsm"]), null) == true
    error_message = "O kms:Decrypt vale só para a chave aws/ssm e só pelo SSM (kms:ViaService)."
  }

  # A AmazonSSMManagedInstanceCore libera ssm:GetParameter em qualquer parâmetro da conta.
  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : s.Effect == "Deny" && length(setsubtract(toset(["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath"]), toset(s.Action))) == 0 && toset(s.NotResource) == toset(["arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/homolog", "arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/homolog/*"]) if s.Sid == "DenyParametersOutsideEnvironment"]), null) == true
    error_message = "A role deve negar a leitura de parâmetros fora de /barberflow/homolog (a política da AWS libera todos)."
  }
}

run "ecr_and_logs" {
  command = apply

  assert {
    condition     = toset([for r in aws_ecr_repository.image : r.name]) == toset(["barberflow-homolog-app", "barberflow-homolog-web"])
    error_message = "Os repositórios do ECR devem ser barberflow-homolog-app e barberflow-homolog-web."
  }

  assert {
    condition     = alltrue([for r in aws_ecr_repository.image : r.image_tag_mutability == "IMMUTABLE" && r.image_scanning_configuration[0].scan_on_push && r.encryption_configuration[0].encryption_type == "AES256"])
    error_message = "Os repositórios do ECR precisam de tags imutáveis, scan no push e criptografia AES256."
  }

  assert {
    condition     = length(aws_ecr_lifecycle_policy.image) == 2 && alltrue([for image, policy in aws_ecr_lifecycle_policy.image : policy.repository == aws_ecr_repository.image[image].name && policy.policy == local.ecr_lifecycle_policy])
    error_message = "Cada repositório do ECR precisa da política de limpeza."
  }

  assert {
    condition     = try(one([for rule in jsondecode(local.ecr_lifecycle_policy).rules : rule.action.type == "expire" && try(rule.selection.countType, "") == "sinceImagePushed" && try(rule.selection.countUnit, "") == "days" && try(rule.selection.countNumber, 0) == 7 if rule.selection.tagStatus == "untagged"]), null) == true
    error_message = "A limpeza do ECR apaga as imagens sem tag 7 dias depois do push."
  }

  assert {
    condition     = try(one([for rule in jsondecode(local.ecr_lifecycle_policy).rules : rule.action.type == "expire" && try(rule.selection.countType, "") == "imageCountMoreThan" && try(rule.selection.countNumber, 0) == var.ecr_keep_tagged_images && contains(try(rule.selection.tagPatternList, []), "*") if rule.selection.tagStatus == "tagged"]), null) == true
    error_message = "A limpeza do ECR guarda as imagens com tag mais novas, quantas a variável ecr_keep_tagged_images mandar."
  }

  assert {
    condition     = toset([for g in aws_cloudwatch_log_group.container : g.name]) == toset(["/barberflow/homolog/app", "/barberflow/homolog/web"]) && alltrue([for g in aws_cloudwatch_log_group.container : g.retention_in_days == 30])
    error_message = "Os log groups dos containers devem ser /barberflow/homolog/app e /barberflow/homolog/web, com 30 dias de retenção."
  }

  assert {
    condition     = toset([for g in aws_cloudwatch_log_group.rds : g.name]) == toset(["/aws/rds/instance/barberflow-homolog-db/error", "/aws/rds/instance/barberflow-homolog-db/slowquery"]) && alltrue([for g in aws_cloudwatch_log_group.rds : g.retention_in_days == 30])
    error_message = "Os log groups do RDS devem existir com a retenção da variável, senão o RDS cria os dele sem prazo de validade."
  }
}

# Os valores do envs/homolog.tfvars.example para o banco.
run "rds" {
  command = apply

  variables {
    db_deletion_protection = false
    db_multi_az            = false
  }

  assert {
    condition     = aws_db_instance.barbershop_db.storage_encrypted && aws_db_instance.barbershop_db.storage_type == "gp3"
    error_message = "O RDS precisa de disco gp3 criptografado."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.publicly_accessible == false
    error_message = "O RDS não pode ser público."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.db_subnet_group_name == aws_db_subnet_group.db.name && toset(aws_db_subnet_group.db.subnet_ids) == toset(aws_subnet.private[*].id)
    error_message = "O RDS deve ficar nas subnets privadas."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.vpc_security_group_ids == toset([aws_security_group.db.id])
    error_message = "O RDS deve usar só o security group do banco."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.backup_retention_period == 7 && aws_db_instance.barbershop_db.backup_window == "06:00-07:00" && aws_db_instance.barbershop_db.maintenance_window == "sun:07:30-sun:08:30"
    error_message = "O RDS guarda 7 dias de backup, com janelas de backup e de manutenção fixas e separadas."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.skip_final_snapshot == false && aws_db_instance.barbershop_db.final_snapshot_identifier == "barberflow-homolog-db-final" && aws_db_instance.barbershop_db.copy_tags_to_snapshot
    error_message = "Apagar o RDS deve gerar o snapshot final barberflow-homolog-db-final."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.deletion_protection == var.db_deletion_protection && aws_db_instance.barbershop_db.deletion_protection == false
    error_message = "A proteção contra exclusão segue a variável (desligada na homolog)."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.multi_az == var.db_multi_az && aws_db_instance.barbershop_db.multi_az == false
    error_message = "O Multi-AZ segue a variável (desligado na homolog)."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.engine == "mysql" && aws_db_instance.barbershop_db.engine_version == "8.4" && aws_db_instance.barbershop_db.auto_minor_version_upgrade
    error_message = "O RDS deve rodar o MySQL 8.4 (a 8.0 saiu do suporte padrão em 31/07/2026), com as correções da 8.4 automáticas."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.enabled_cloudwatch_logs_exports == toset(["error", "slowquery"]) && toset(keys(aws_cloudwatch_log_group.rds)) == aws_db_instance.barbershop_db.enabled_cloudwatch_logs_exports
    error_message = "O RDS exporta os logs de erro e de consultas lentas, e cada um tem o seu log group."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.parameter_group_name == aws_db_parameter_group.mysql.name && aws_db_parameter_group.mysql.family == "mysql8.4"
    error_message = "O RDS usa o parameter group próprio, da família mysql8.4."
  }

  assert {
    condition     = alltrue([for want in ["require_secure_transport=1", "slow_query_log=1", "long_query_time=1", "log_output=FILE"] : contains([for p in aws_db_parameter_group.mysql.parameter : "${p.name}=${p.value}"], want)])
    error_message = "O parameter group exige TLS (require_secure_transport) e grava em arquivo as consultas de mais de 1 segundo (o log só chega ao CloudWatch com log_output = FILE)."
  }

  assert {
    condition     = aws_db_instance.barbershop_db.identifier == "barberflow-homolog-db" && aws_db_instance.barbershop_db.port == 3306
    error_message = "O RDS se chama barberflow-homolog-db e escuta na 3306."
  }

  # Decisão de 13/11: a senha fica no main.tf. A rotação automática do Secrets Manager
  # trocaria a senha a cada 7 dias e derrubaria a app, que lê a senha do SSM na subida.
  assert {
    condition     = aws_db_instance.barbershop_db.manage_master_user_password != true
    error_message = "Sem manage_master_user_password: a senha do RDS continua a do main.tf."
  }

  # Lê o texto dos .tf, menos o main.tf (o único lugar da senha, que o teste não abre).
  assert {
    condition     = contains(fileset(path.module, "*.tf"), "ssm.tf") && length(flatten([for f in fileset(path.module, "*.tf") : regexall("(?m)^\\s*password\\s*=\\s*\"", file("${path.module}/${f}")) if f != "main.tf"])) == 0 && can(regex("(?m)^\\s*value_wo\\s*=\\s*aws_db_instance\\.barbershop_db\\.password\\s*$", file("${path.module}/ssm.tf")))
    error_message = "A senha do banco só pode estar escrita no main.tf. O ssm.tf passa o valor por referência (value_wo = aws_db_instance.barbershop_db.password)."
  }
}

# Os valores do envs/homolog.tfvars.example para o cache.
run "cache" {
  command = apply

  variables {
    cache_num_cache_clusters = 1
    cache_automatic_failover = false
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.engine == "valkey" && aws_elasticache_replication_group.cache.engine_version == "9.1" && aws_elasticache_replication_group.cache.port == 6379
    error_message = "O cache roda Valkey 9.1 na porta 6379."
  }

  assert {
    condition     = tobool(aws_elasticache_replication_group.cache.at_rest_encryption_enabled) && aws_elasticache_replication_group.cache.transit_encryption_enabled
    error_message = "O cache precisa de criptografia em repouso e em trânsito (TLS)."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.security_group_ids == toset([aws_security_group.cache.id])
    error_message = "O cache deve usar só o security group do cache."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.subnet_group_name == aws_elasticache_subnet_group.cache.name && toset(aws_elasticache_subnet_group.cache.subnet_ids) == toset(aws_subnet.private[*].id)
    error_message = "O cache deve ficar nas subnets privadas."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.cluster_mode == "disabled" && aws_elasticache_replication_group.cache.num_cache_clusters == 1 && aws_elasticache_replication_group.cache.automatic_failover_enabled == false && aws_elasticache_replication_group.cache.multi_az_enabled == false
    error_message = "Na homolog, o cache tem um nó só, sem cluster mode e sem failover."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.node_type == "cache.t4g.micro" && aws_elasticache_replication_group.cache.replication_group_id == "barberflow-homolog-cache"
    error_message = "O cache padrão é um cache.t4g.micro chamado barberflow-homolog-cache."
  }
}

# Com domínio no app_url e provedor de e-mail no mail_host e no mail_port: o APP_URL fica com o
# domínio, o MAIL_HOST passa a existir, e o MAIL_PORT e a saída SMTP do app seguem a variável.
# O domínio em https vai com o certificado (a feat/infra-computacao recusa um sem o outro), e
# por isso o cookie de sessão fica só por HTTPS.
run "ssm_parameters" {
  command = apply

  variables {
    app_url         = "https://homolog.barberflow.example.com"
    certificate_arn = "arn:aws:acm:us-east-1:123456789012:certificate/11111111-2222-3333-4444-555555555555"
    mail_host       = "email-smtp.us-east-1.amazonaws.com"
    mail_port       = 465
  }

  assert {
    condition = toset(concat([for p in aws_ssm_parameter.terraform_managed : p.name], [for p in aws_ssm_parameter.operator_managed : p.name], [aws_ssm_parameter.db_password.name])) == toset([for name in [
      "APP_DEBUG", "APP_ENV", "APP_KEY", "APP_MAINTENANCE_DRIVER", "APP_MAINTENANCE_STORE", "APP_NAME", "APP_URL",
      "CACHE_STORE", "DB_CONNECTION", "DB_DATABASE", "DB_HOST", "DB_PASSWORD", "DB_PORT", "DB_USERNAME",
      "LOG_CHANNEL", "LOG_LEVEL", "MAIL_FROM_ADDRESS", "MAIL_HOST", "MAIL_MAILER", "MAIL_PORT", "MYSQL_ATTR_SSL_CA",
      "REDIS_HOST", "REDIS_PORT", "RUN_MIGRATIONS", "SESSION_DRIVER", "SESSION_SECURE_COOKIE", "TRUSTED_PROXIES", "image_tag",
    ] : "/barberflow/homolog/${name}"])
    error_message = "Os parâmetros do SSM devem ser exatamente os esperados, todos em /barberflow/homolog/."
  }

  assert {
    condition     = aws_ssm_parameter.db_password.type == "SecureString" && aws_ssm_parameter.operator_managed["APP_KEY"].type == "SecureString"
    error_message = "DB_PASSWORD e APP_KEY precisam ser SecureString."
  }

  assert {
    condition     = alltrue([for p in aws_ssm_parameter.terraform_managed : p.type == "String"]) && aws_ssm_parameter.operator_managed["image_tag"].type == "String"
    error_message = "Os parâmetros que não são segredo ficam como String."
  }

  # Segredo vai pelo atributo write-only value_wo, que nunca fica no state: a senha do RDS (por
  # referência) e o APP_KEY (o Terraform manda só o valor provisório e nunca lê de volta a chave
  # que o operador gravar). O atributo value guardaria no state o valor lido da AWS, inclusive
  # o de um SecureString; nenhum parâmetro do ssm.tf pode usar.
  assert {
    condition     = aws_ssm_parameter.db_password.value_wo_version == 1 && aws_ssm_parameter.operator_managed["APP_KEY"].value_wo_version == 1 && !can(regex("(?m)^\\s*value\\s*=", file("${path.module}/ssm.tf")))
    error_message = "DB_PASSWORD e APP_KEY vão pelo value_wo, na versão 1, e nenhum parâmetro do ssm.tf usa o atributo value (ele traria para o state a chave que o operador gravar)."
  }

  assert {
    condition     = !startswith(local.ssm_operator_parameters["APP_KEY"].initial_value, "base64:") && aws_ssm_parameter.operator_managed["image_tag"].insecure_value == "PREENCHER_PELO_DEPLOY"
    error_message = "APP_KEY nasce com um valor provisório (não uma chave de verdade) e image_tag com PREENCHER_PELO_DEPLOY, nunca com latest."
  }

  # Os mesmos valores do bloco de produção do docker-compose.prod.yml.
  assert {
    condition = alltrue([
      aws_ssm_parameter.terraform_managed["APP_ENV"].insecure_value == "production",
      aws_ssm_parameter.terraform_managed["APP_DEBUG"].insecure_value == "false",
      aws_ssm_parameter.terraform_managed["LOG_CHANNEL"].insecure_value == "stderr",
      aws_ssm_parameter.terraform_managed["LOG_LEVEL"].insecure_value == "info",
    ])
    error_message = "O bloco de produção (APP_ENV, APP_DEBUG, LOG_CHANNEL e LOG_LEVEL) deve ter os valores do docker-compose.prod.yml."
  }

  assert {
    condition = alltrue([
      aws_ssm_parameter.terraform_managed["DB_CONNECTION"].insecure_value == "mysql",
      aws_ssm_parameter.terraform_managed["DB_HOST"].insecure_value == aws_db_instance.barbershop_db.address,
      aws_ssm_parameter.terraform_managed["DB_PORT"].insecure_value == "3306",
      aws_ssm_parameter.terraform_managed["DB_DATABASE"].insecure_value == aws_db_instance.barbershop_db.db_name,
      aws_ssm_parameter.terraform_managed["DB_USERNAME"].insecure_value == aws_db_instance.barbershop_db.username,
      aws_ssm_parameter.terraform_managed["MYSQL_ATTR_SSL_CA"].insecure_value == "/etc/ssl/certs/rds-global-bundle.pem",
    ])
    error_message = "Os parâmetros do banco devem apontar para o RDS, com o bundle de CA da imagem para o TLS."
  }

  assert {
    condition = alltrue([
      aws_ssm_parameter.terraform_managed["REDIS_HOST"].insecure_value == "tls://${aws_elasticache_replication_group.cache.primary_endpoint_address}",
      aws_ssm_parameter.terraform_managed["REDIS_PORT"].insecure_value == "6379",
      aws_ssm_parameter.terraform_managed["CACHE_STORE"].insecure_value == "redis",
      aws_ssm_parameter.terraform_managed["SESSION_DRIVER"].insecure_value == "redis",
      aws_ssm_parameter.terraform_managed["APP_MAINTENANCE_DRIVER"].insecure_value == "cache",
      aws_ssm_parameter.terraform_managed["APP_MAINTENANCE_STORE"].insecure_value == "redis",
    ])
    error_message = "Cache, sessão e modo de manutenção ficam no Valkey, com tls:// no REDIS_HOST."
  }

  assert {
    condition = alltrue([
      aws_ssm_parameter.terraform_managed["APP_NAME"].insecure_value == "BarberFlow",
      aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value == "https://homolog.barberflow.example.com",
      aws_ssm_parameter.terraform_managed["RUN_MIGRATIONS"].insecure_value == "true",
      aws_ssm_parameter.terraform_managed["SESSION_SECURE_COOKIE"].insecure_value == "true",
      aws_ssm_parameter.terraform_managed["MAIL_MAILER"].insecure_value == "smtp",
      aws_ssm_parameter.terraform_managed["MAIL_FROM_ADDRESS"].insecure_value == "nao-responda@example.com",
      aws_ssm_parameter.terraform_managed["MAIL_HOST"].insecure_value == "email-smtp.us-east-1.amazonaws.com",
      aws_ssm_parameter.terraform_managed["MAIL_PORT"].insecure_value == "465",
    ])
    error_message = "APP_NAME, APP_URL, migrations, cookie de sessão e e-mail devem seguir as variáveis."
  }

  # A saída SMTP do app (sg.tf) abre a mesma porta do MAIL_PORT.
  assert {
    condition     = aws_vpc_security_group_egress_rule.app_to_internet["smtp"].from_port == 465 && aws_vpc_security_group_egress_rule.app_to_internet["smtp"].to_port == 465
    error_message = "A saída SMTP do app deve abrir a porta da variável mail_port (465 neste teste), a mesma do MAIL_PORT."
  }
}

run "outputs" {
  command = apply

  assert {
    condition = alltrue([
      output.alb_security_group_id == aws_security_group.alb.id,
      output.app_security_group_id == aws_security_group.app.id,
      output.db_security_group_id == aws_security_group.db.id,
      output.cache_security_group_id == aws_security_group.cache.id,
    ])
    error_message = "As saídas devem mostrar os IDs dos 4 security groups."
  }

  assert {
    condition     = output.ec2_instance_profile_name == aws_iam_instance_profile.ec2.name && output.ec2_instance_profile_arn == aws_iam_instance_profile.ec2.arn
    error_message = "As saídas devem mostrar o nome e o ARN do instance profile."
  }

  assert {
    condition     = toset(keys(output.ecr_repository_urls)) == toset(["app", "web"]) && alltrue([for image, url in output.ecr_repository_urls : url == aws_ecr_repository.image[image].repository_url])
    error_message = "A saída ecr_repository_urls deve ter o endereço dos repositórios app e web."
  }

  assert {
    condition     = toset(keys(output.log_group_names)) == toset(["app", "web"]) && output.log_group_names["app"] == "/barberflow/homolog/app" && output.log_group_names["web"] == "/barberflow/homolog/web"
    error_message = "A saída log_group_names deve ter os log groups app e web."
  }

  assert {
    condition     = output.db_endpoint == aws_db_instance.barbershop_db.address && output.db_port == 3306 && output.db_identifier == "barberflow-homolog-db"
    error_message = "As saídas devem mostrar o endereço, a porta e o nome do RDS."
  }

  assert {
    condition     = output.db_subnet_group_name == aws_db_subnet_group.db.name && output.db_parameter_group_name == aws_db_parameter_group.mysql.name
    error_message = "As saídas devem mostrar o grupo de subnets e o parameter group do RDS, que o restore usa."
  }

  assert {
    condition     = output.cache_primary_endpoint == aws_elasticache_replication_group.cache.primary_endpoint_address && output.ssm_prefix == "/barberflow/homolog"
    error_message = "As saídas devem mostrar o endereço do primário do cache e o caminho do SSM."
  }
}

# Os valores do envs/prod.tfvars.example: banco Multi-AZ e protegido, cache com réplica e failover.
run "prod" {
  command = apply

  variables {
    environment              = "prod"
    vpc_cidr                 = "10.30.0.0/16"
    db_deletion_protection   = true
    db_multi_az              = true
    cache_num_cache_clusters = 2
    cache_automatic_failover = true
  }

  assert {
    condition     = aws_db_instance.barbershop_db.deletion_protection && aws_db_instance.barbershop_db.multi_az && aws_db_instance.barbershop_db.identifier == "barberflow-prod-db"
    error_message = "Na prod, o RDS tem proteção contra exclusão e Multi-AZ."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.num_cache_clusters == 2 && aws_elasticache_replication_group.cache.automatic_failover_enabled && aws_elasticache_replication_group.cache.multi_az_enabled
    error_message = "Na prod, o cache tem uma réplica e failover automático entre zonas."
  }

  assert {
    condition     = alltrue([for name in concat([for p in aws_ssm_parameter.terraform_managed : p.name], [for p in aws_ssm_parameter.operator_managed : p.name], [aws_ssm_parameter.db_password.name]) : startswith(name, "/barberflow/prod/")])
    error_message = "Na prod, os parâmetros ficam em /barberflow/prod/."
  }

  assert {
    condition     = try(one([for s in jsondecode(local.ec2_role_policy).Statement : toset(s.Resource) == toset(["arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/prod", "arn:aws:ssm:us-east-1:123456789012:parameter/barberflow/prod/*"]) if s.Sid == "ReadEnvironmentParameters"]), null) == true
    error_message = "A role da prod lê só /barberflow/prod."
  }

  assert {
    condition     = toset([for g in aws_cloudwatch_log_group.container : g.name]) == toset(["/barberflow/prod/app", "/barberflow/prod/web"]) && toset([for r in aws_ecr_repository.image : r.name]) == toset(["barberflow-prod-app", "barberflow-prod-web"])
    error_message = "Na prod, log groups e repositórios do ECR levam o nome do ambiente."
  }
}

# Sem nada além do ambiente: os padrões das variáveis são os seguros, e sem domínio o APP_URL é
# o DNS do ALB.
run "defaults_without_domain" {
  command = apply

  assert {
    condition     = aws_db_instance.barbershop_db.deletion_protection && aws_db_instance.barbershop_db.multi_az == false
    error_message = "Por padrão, o RDS tem proteção contra exclusão e fica numa zona só."
  }

  assert {
    condition     = aws_elasticache_replication_group.cache.num_cache_clusters == 1 && aws_elasticache_replication_group.cache.automatic_failover_enabled == false
    error_message = "Por padrão, o cache tem um nó só, sem failover."
  }

  # Sem domínio, o APP_URL é o DNS do ALB, em HTTP (feat/infra-computacao). Sem provedor de
  # e-mail, o MAIL_HOST não existe. São 24 parâmetros: os 22 da feat/infra-dados-seguranca, o
  # APP_URL e o TRUSTED_PROXIES.
  assert {
    condition     = aws_ssm_parameter.terraform_managed["APP_URL"].insecure_value == "http://${aws_lb.main.dns_name}" && !contains(keys(aws_ssm_parameter.terraform_managed), "MAIL_HOST") && length(aws_ssm_parameter.terraform_managed) == 24
    error_message = "Com app_url vazio, o APP_URL é http:// com o DNS do ALB; com mail_host vazio, o MAIL_HOST não é criado (o SSM não aceita valor vazio)."
  }

  assert {
    condition     = aws_ssm_parameter.terraform_managed["MAIL_PORT"].insecure_value == "587" && aws_vpc_security_group_egress_rule.app_to_internet["smtp"].from_port == 587
    error_message = "Por padrão, o e-mail sai pela porta 587 (MAIL_PORT e saída SMTP do app)."
  }
}

run "rejects_failover_with_single_node" {
  command = plan

  variables {
    cache_num_cache_clusters = 1
    cache_automatic_failover = true
  }

  expect_failures = [var.cache_automatic_failover]
}

run "rejects_cache_without_nodes" {
  command = plan

  variables {
    cache_num_cache_clusters = 0
  }

  expect_failures = [var.cache_num_cache_clusters]
}

run "rejects_invalid_cache_node_type" {
  command = plan

  variables {
    cache_node_type = "t4g.micro"
  }

  expect_failures = [var.cache_node_type]
}

run "rejects_log_retention_not_accepted_by_cloudwatch" {
  command = plan

  variables {
    log_retention_days = 10
  }

  expect_failures = [var.log_retention_days]
}

run "rejects_keeping_a_single_image" {
  command = plan

  variables {
    ecr_keep_tagged_images = 1
  }

  expect_failures = [var.ecr_keep_tagged_images]
}

run "rejects_app_url_with_path" {
  command = plan

  variables {
    app_url = "https://barberflow.example.com/"
  }

  expect_failures = [var.app_url]
}

run "rejects_log_mailer" {
  command = plan

  variables {
    mail_mailer = "log"
  }

  expect_failures = [var.mail_mailer]
}

run "rejects_invalid_mail_from" {
  command = plan

  variables {
    mail_from_address = "nao-responda"
  }

  expect_failures = [var.mail_from_address]
}

run "rejects_mail_host_with_protocol" {
  command = plan

  variables {
    mail_host = "smtp://email-smtp.us-east-1.amazonaws.com"
  }

  expect_failures = [var.mail_host]
}

# A AWS bloqueia a porta 25 nas instâncias EC2.
run "rejects_mail_port_25" {
  command = plan

  variables {
    mail_port = 25
  }

  expect_failures = [var.mail_port]
}
