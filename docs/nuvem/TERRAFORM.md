# Terraform do BarberFlow

Este guia explica a infraestrutura como código do BarberFlow na AWS: o que cada parte cria, como validar e testar no seu computador sem conta da AWS, como criar o bucket do state remoto, como escolher o ambiente, a ordem do primeiro deploy e o dia a dia das instâncias (Session Manager, administrador, logs e capacidade). O backup e o restore do banco ficam no `docs/nuvem/BACKUP.md`.

Nada daqui foi aplicado na AWS. Aplicar cria recursos que cobram por hora, então só acontece com a conta do grupo (veja "Aplicar na AWS").

O Terraform chega em cinco branches:

| Branch | Dono | O que traz |
|---|---|---|
| `feat/infra-rede` | Manoel | Versões, state remoto, variáveis, VPC e subnets (seção "A rede") |
| `feat/infra-dados-seguranca` | Eduardo | Security groups, IAM, ECR, logs, RDS, cache e parâmetros (seção "Dados e segurança") |
| `feat/infra-computacao` | Manoel | ALB, Auto Scaling, `user_data` e orçamento |
| `feat/ci-cd` | José (CI) e Manoel (CD) | `cicd.tf`, com a role de deploy por OIDC (o provedor OIDC só é criado se uma variável pedir), e o CI rodando `fmt -check`, `validate`, `test` e `trivy config` |
| `feat/observabilidade` | Leonam | `monitoring.tf`, com o SNS por e-mail, os alarmes de ALB, Auto Scaling, RDS e ElastiCache e o dashboard do CloudWatch |

## Arquivos

| Arquivo | Para que serve |
|---|---|
| `terraform/versions.tf` | Versão mínima do Terraform (1.11) e do provider AWS (série 6, a partir da 6.73) |
| `terraform/.terraform.lock.hcl` | Versão exata do provider (6.73.0) e os hashes para Linux, Windows e Mac. Vai para o Git |
| `terraform/backend.tf` | State remoto no S3, com a trava (`use_lockfile`) e a criptografia (`encrypt`); bucket, key e região vêm do `backend.hcl` |
| `terraform/backend.hcl.example` | Modelo do `backend.hcl`: bucket, key e região |
| `terraform/providers.tf` | Provider AWS com a região da variável e as tags padrão |
| `terraform/variables.tf` | Variáveis da base, da rede, dos dados e da computação |
| `terraform/locals.tf` | Prefixo dos nomes (ex.: `barberflow-homolog`) e as tags |
| `terraform/network.tf` | VPC, subnets, internet gateway, tabelas de rotas e o security group padrão sem regras |
| `terraform/sg.tf` | Security groups do ALB, do app, do banco e do cache, com as regras de entrada e de saída |
| `terraform/iam.tf` | Role e instance profile das instâncias do app, com a política mínima |
| `terraform/ecr.tf` | Repositórios do ECR das imagens `app` e `web`, com a limpeza automática |
| `terraform/logs.tf` | Log groups do CloudWatch: containers `app` e `web` e os logs do RDS |
| `terraform/main.tf` | Recurso da Sprint 1 que ficou: o RDS, com o grupo de subnets e o parameter group (veja "Senha do RDS"). A EC2 solta saiu na `feat/infra-computacao` |
| `terraform/cache.tf` | ElastiCache (Valkey) para cache e sessões |
| `terraform/ssm.tf` | Parâmetros do SSM que viram o `.env` da EC2 |
| `terraform/alb.tf` | ALB, target group com health check no `/up` e as portas 80 e 443 |
| `terraform/compute.tf` | Launch template, Auto Scaling e a escala pela CPU |
| `terraform/templates/user_data.sh.tftpl` | Script de subida das instâncias: Docker, compose, logs, `.env` do SSM e containers |
| `terraform/budget.tf` | Orçamento mensal com aviso por e-mail |
| `terraform/outputs.tf` | IDs da VPC e das subnets, zonas, security groups, instance profile, ECR, log groups, banco, cache, caminho do SSM, ALB, `APP_URL`, Auto Scaling, launch template e o SHA-256 do compose embutido no `user_data` |
| `terraform/envs/*.tfvars.example` | Valores de cada ambiente, homolog e prod |
| `terraform/tests/rede.tftest.hcl` | Testes da rede com o provider simulado |
| `terraform/tests/dados_seguranca.tftest.hcl` | Testes dos dados e da segurança com o provider simulado |
| `terraform/tests/computacao.tftest.hcl` | Testes da computação (ALB, instâncias, `user_data`, Auto Scaling e orçamento) com o provider simulado |
| `terraform/tests/user_data/` | O `user_data` pronto para o ShellCheck e o teste da montagem do `.env` num container do Amazon Linux 2023 (seção "Como o user_data foi testado") |
| `terraform/bootstrap/` | Stack separada que cria o bucket do state, com o próprio lock e o próprio teste |
| `terraform/.trivyignore.yaml` | Alertas do Trivy aceitos de propósito, cada um preso ao arquivo do recurso e com o motivo |
| `terraform/.trivyignore` | Vazio de propósito: avisa que a lista fica no `.trivyignore.yaml` (veja "Trivy") |
| `terraform/.gitignore` | Tira do Git o state, os `.tfvars`, o `backend.hcl`, os planos salvos e a pasta `.terraform` |

## A rede

| Peça | Homolog (valores padrão) | Para que serve |
|---|---|---|
| VPC | `10.20.0.0/16`, com DNS ligado | Rede isolada do projeto |
| Subnets públicas | `10.20.0.0/24` e `10.20.1.0/24`, uma em cada zona | ALB e instâncias do Auto Scaling |
| Subnets privadas | `10.20.10.0/24` e `10.20.11.0/24`, uma em cada zona | Banco (RDS) e cache |
| Internet gateway | Um para a VPC | Entrada e saída da internet nas subnets públicas |
| Tabela de rotas pública | `0.0.0.0/0` para o internet gateway | Usada pelas duas subnets públicas |
| Tabela de rotas privada | Só a rota local da VPC | Banco e cache sem nenhum caminho para a internet |
| Security group padrão | Sem regra nenhuma | O que cair nele por engano fica isolado |

- As zonas são as duas primeiras zonas de disponibilidade da região, em ordem alfabética (`az_count = 2`). Com `az_count = 3`, cada tipo de subnet ganha uma terceira, numa terceira zona.
- Local Zones e Wavelength Zones ficam de fora pelo filtro `zone-type` do `network.tf`, mesmo que a conta tenha entrado nelas. Sem o filtro, uma Local Zone como `us-east-1-bos-1a` viria antes de `us-east-1a` na ordem alfabética e levaria subnets para um lugar onde RDS, cache e ALB podem não existir.
- A VPC pode ir de `/16` a `/19`. Cada subnet recebe 1/256 da VPC: um `/24` com a `/16` e um `/27` com a `/19`, o mínimo que o ALB aceita. Uma VPC `/20` daria subnets `/28`, e a variável recusa.
- A prod usa outra faixa no exemplo (`10.30.0.0/16`), para as duas VPCs poderem se conectar um dia sem conflito de IP.
- Não há NAT Gateway, que cobra por hora e por GB. As instâncias ficam nas subnets públicas, com IP público dado pelo launch template, e os security groups deixam entrar só o que vem do ALB. Banco e cache não precisam sair para a internet.
- Nenhuma subnet dá IP público sozinha (`map_public_ip_on_launch = false`).
- A tabela privada declara `route = []`: se alguém acrescentar uma rota pelo console, o próximo `apply` apaga.
- Rota nova, nas duas tabelas, entra num bloco `route` dentro da própria tabela, nunca num `aws_route` avulso (veja "Para as próximas branches").
- O security group padrão de toda VPC nasce liberando o tráfego entre os seus membros e toda a saída. Aqui ele fica sem regra nenhuma, e cada recurso usa o security group próprio.

## Dados e segurança

Parte do Eduardo, na branch `feat/infra-dados-seguranca`. Como o resto do Terraform, está validada só no código: `validate`, `test` com o provider simulado e `trivy config`.

### Security groups (`sg.tf`)

| Security group | Entrada | Saída |
|---|---|---|
| `barberflow-<ambiente>-alb` | 80 e 443 da internet, em IPv4 e IPv6 | Só a 80 do app (requisições e health check) |
| `barberflow-<ambiente>-app` | 80, só do ALB | 443 e a porta do SMTP (`mail_port`) para a internet; 3306 só para o banco e 6379 só para o cache |
| `barberflow-<ambiente>-db` | 3306, só do app | Nenhuma |
| `barberflow-<ambiente>-cache` | 6379, só do app | Nenhuma |

- Cada camada só aceita a da frente. Um IP da internet não alcança o app, o banco nem o cache, mesmo com a instância tendo IP público.
- A saída do app vai só pelas portas de que ele precisa. Como não há NAT Gateway nem VPC endpoints, a instância fala com a AWS e com a internet pelo IP público: HTTPS (443) para o ECR (e as camadas das imagens, que ficam no S3), o SSM e o Session Manager, o CloudWatch Logs, os repositórios do dnf e o GitHub do plugin compose; e a porta do SMTP do provedor de e-mail (variável `mail_port`, padrão 587). Para o banco e o cache, a saída vai só ao security group de cada um. DNS da VPC, metadados da instância (IMDS) e Time Sync da AWS não passam por security group.
- Precisa de outra porta de saída (no `user_data` da `feat/infra-computacao`, por exemplo)? A regra nova entra no `sg.tf` e no teste, sem liberar tudo.
- Banco e cache não abrem conexão para fora; só respondem às conexões que receberam.
- Nenhuma porta 22. O acesso às instâncias é pelo Session Manager (veja "IAM").
- Cada regra é um recurso `aws_vpc_security_group_ingress_rule` ou `aws_vpc_security_group_egress_rule` que aponta direto para o seu security group, e assim o Trivy liga a regra ao grupo. Regra escrita dentro do `aws_security_group` (bloco `ingress { }` ou atributo `ingress = [{ ... }]`, e o mesmo para `egress`) e o `aws_security_group_rule` antigo ficam de fora: a documentação do provider não permite misturar os jeitos, porque um apaga a regra do outro. O teste falha se achar um deles.
- A descrição do security group e da regra fica sem acento: a AWS só aceita letras sem acento, números, espaço e `._-:/()#,@[]+=&;{}!$*` nesse campo, e o erro só apareceria no `apply`. O teste confere.

### IAM (`iam.tf`)

As instâncias do app usam a role `barberflow-<ambiente>-ec2`, pelo instance profile de mesmo nome, que o launch template da `feat/infra-computacao` recebe.

| Para quê | Permissão | Onde vale |
|---|---|---|
| Entrar na instância sem SSH | Política da AWS `AmazonSSMManagedInstanceCore` (Session Manager) | A instância |
| Login no ECR | `ecr:GetAuthorizationToken` | `*`, porque a ação não aceita outro recurso |
| Baixar as imagens | `ecr:BatchCheckLayerAvailability`, `ecr:BatchGetImage` e `ecr:GetDownloadUrlForLayer` | Só os 2 repositórios do ambiente |
| Logs dos containers | `logs:CreateLogStream` e `logs:PutLogEvents` | Só os log groups `app` e `web` do ambiente |
| Ler os parâmetros | `ssm:GetParameter`, `ssm:GetParameters` e `ssm:GetParametersByPath` | Só `/barberflow/<ambiente>` e o que está dentro dele |
| Decifrar os SecureString | `kms:Decrypt` | Só a chave `aws/ssm`, e só quando o pedido vem do SSM (`kms:ViaService`) |
| Não ler outro ambiente | Negação de `ssm:GetParameter*` | Todo parâmetro fora de `/barberflow/<ambiente>` |

- A `AmazonSSMManagedInstanceCore` libera `ssm:GetParameter` e `ssm:GetParameters` em qualquer parâmetro da conta. Sem a negação, uma instância da homolog leria a senha do banco da prod. Uma negação (`Deny`) vale mais que qualquer permissão (`Allow`).
- A chave `aws/ssm` já deixa qualquer usuário da conta decifrar pelo SSM. O `kms:Decrypt` da política deixa essa permissão escrita e presa a essa chave.
- As duas políticas ficam em `jsonencode()` nos `locals` (`local.ec2_assume_role_policy` e `local.ec2_role_policy`), para o teste ler.
- O Trivy não avalia a política, porque ela usa ARNs que só existem depois do `apply`. Quem confere é o `tests/dados_seguranca.tftest.hcl`: nenhuma ação com curinga, nenhum `Resource "*"` fora do login no ECR, cada permissão presa ao seu recurso e nenhum recurso novo ligado à role (um anexo da `AdministratorAccess`, por exemplo) sem passar pelo teste.

### ECR (`ecr.tf`)

- Repositórios `barberflow-<ambiente>-app` e `barberflow-<ambiente>-web`, um para cada alvo do `docker/php/Dockerfile` que vai para a AWS.
- Tags imutáveis: uma tag aponta sempre para a mesma imagem. O deploy da `feat/ci-cd` publica com a tag do commit, e o rollback volta para exatamente o que rodava antes.
- Scan no push: o ECR procura vulnerabilidades conhecidas em cada imagem nova.
- Criptografia AES256, com a chave da AWS (motivo no `.trivyignore.yaml`).
- Limpeza automática: imagem sem tag sai 7 dias depois do push, e cada repositório guarda só as `ecr_keep_tagged_images` imagens com tag mais novas (padrão 10). O rollback só volta para uma imagem que ainda está lá.
- O parâmetro `image_tag` nasce com o valor provisório `PREENCHER_PELO_DEPLOY`, que não aponta para imagem nenhuma. Até o primeiro deploy, que grava a tag do commit, o `user_data` para com a mensagem "nenhum deploy feito ainda" (veja "Primeiro deploy"). A tag `latest` nunca é usada: com tags imutáveis, uma `latest` publicada à mão ficaria presa para sempre a uma imagem que não passou pelo CI.

### Logs (`logs.tf`)

| Log group | O que guarda |
|---|---|
| `/barberflow/<ambiente>/app` | PHP-FPM e agendador (containers `app` e `scheduler`) |
| `/barberflow/<ambiente>/web` | Nginx (container `web`) |
| `/aws/rds/instance/barberflow-<ambiente>-db/error` | Log de erro do MySQL |
| `/aws/rds/instance/barberflow-<ambiente>-db/slowquery` | Consultas de mais de 1 segundo |

- Todos guardam `log_retention_days` dias (padrão 30).
- Os dois do RDS nascem antes do banco. Se não existissem, o RDS criaria os dele, sem prazo de validade.
- **Cada serviço escreve no seu grupo** (feito na `feat/infra-computacao`). O `daemon.json` da EC2 manda os logs de todos os containers para o grupo `app`, que fica como padrão (atende o `app` e o `scheduler`). O `docker-compose.prod.yml` tem um `logging:` só no serviço `web`, apontando para o grupo `web`, com a região e o nome do grupo vindo do `.env` que o `user_data` monta. O `docker-compose.homolog.yml` volta o `web` ao driver padrão do Docker (`json-file`) com `!override`, e a homolog continua subindo no Docker Desktop sem credencial da AWS. Como ler os logs: seção "Logs no CloudWatch".

### RDS (`main.tf`)

| O quê | Como está | Por quê |
|---|---|---|
| Versão | MySQL 8.4, com as correções da 8.4 aplicadas sozinhas na janela de manutenção | A 8.0 saiu do suporte padrão do RDS em 31/07/2026 |
| Classe e disco | `db.t3.micro` (a da Sprint 1) e 20 GB em gp3, criptografado | |
| Nome | `barberflow-<ambiente>-db` | Usado pelos log groups, pelos alarmes da `feat/observabilidade` e pelo `docs/nuvem/BACKUP.md` |
| Rede | Subnets privadas, security group do banco e sem IP público | Só o app alcança a porta 3306 |
| TLS | Obrigatório (`require_secure_transport = 1` no parameter group) | A aplicação conecta com o bundle de CA da imagem (`MYSQL_ATTR_SSL_CA`) |
| Logs | Erro e consultas de mais de 1 segundo, no CloudWatch | O `log_output = FILE` é o que leva o log ao CloudWatch; o padrão do RDS grava numa tabela dentro do banco |
| Backup | 7 dias, das 03:00 às 04:00 (Brasília), e snapshot final `barberflow-<ambiente>-db-final` | Restore no `docs/nuvem/BACKUP.md` |
| Manutenção | Domingo, das 04:30 às 05:30 (Brasília) | Barbearia fechada |
| Proteção contra exclusão | `db_deletion_protection`: `true` na prod, `false` na homolog | Na prod, nem o `terraform destroy` apaga o banco |
| Multi-AZ | `db_multi_az`: `true` na prod, `false` na homolog | Na prod, uma réplica de outra zona assume sozinha |
| Login por IAM e Performance Insights | Desligados | Motivos no `.trivyignore.yaml` (AWS-0176 e AWS-0133) |

O backup, o restore passo a passo, o RPO (cerca de 5 minutos) e o RTO (estimado em 45 a 60 minutos) estão no `docs/nuvem/BACKUP.md`.

### Cache (`cache.tf`)

- ElastiCache com Valkey 9.1, o fork de código aberto do Redis, que a extensão redis do PHP usa sem mudança. Sem cluster mode: um primário e, na prod, uma réplica em outra zona, com failover automático e Multi-AZ.
- `cache.t4g.micro` por padrão (`cache_node_type`), nas subnets privadas, com o security group do cache.
- Criptografia em repouso e em trânsito (TLS). A aplicação conecta com `REDIS_HOST=tls://<endereço do primário>` e `REDIS_PORT=6379`, e o `tls://` liga o TLS na extensão redis. A homolog local roda um Redis sem TLS, então o TLS daqui fica validado só no código.
- Sem senha (AUTH): só o security group do app alcança a porta 6379.
- Sem backup: guarda cache e sessões, que se refazem. Se o cache cair, quem estava logado entra de novo.
- Manutenção no domingo, das 05:30 às 06:30 (Brasília).

| Variável | Homolog | Prod |
|---|---|---|
| `cache_num_cache_clusters` | `1` | `2` |
| `cache_automatic_failover` | `false` | `true` |

### Parâmetros do SSM (`ssm.tf`)

Os parâmetros ficam em `/barberflow/<ambiente>/` e viram o `.env` da EC2. O `user_data` lê o caminho inteiro (`aws ssm get-parameters-by-path --path /barberflow/<ambiente> --recursive --with-decryption`) e grava uma linha `NOME='valor'` por parâmetro, ao lado do `docker-compose.prod.yml` (formato e regras na seção "Launch template e user_data"). O bloco de produção desse compose (`APP_ENV`, `APP_DEBUG`, `LOG_CHANNEL`, `LOG_LEVEL`, `CACHE_STORE` e `SESSION_DRIVER`) vale mais que o `.env`. Os mesmos valores estão também no SSM, para o `.env` da EC2 ficar completo sozinho, inclusive para quem rodar a imagem fora do compose.

| Parâmetro | Tipo | Valor | Quem cuida do valor |
|---|---|---|---|
| `DB_PASSWORD` | SecureString | A senha do RDS no `main.tf`, por referência | Terraform (`value_wo`) |
| `APP_KEY` | SecureString | Provisório (`PREENCHER_COM_AWS_SSM_PUT_PARAMETER`), gravado pelo `value_wo` | Operador (veja "Preencher o APP_KEY") |
| `image_tag` | String | Provisório (`PREENCHER_PELO_DEPLOY`) | Deploy da `feat/ci-cd` |
| `APP_NAME` | String | `BarberFlow` | Terraform |
| `APP_ENV`, `APP_DEBUG`, `LOG_CHANNEL` e `LOG_LEVEL` | String | `production`, `false`, `stderr` e `info`, os mesmos do `docker-compose.prod.yml` | Terraform |
| `APP_URL` | String | Sem domínio, `http://<DNS do ALB>`; com domínio, a variável `app_url` (`https://` junto com o certificado) | Terraform |
| `DB_CONNECTION` | String | `mysql` | Terraform |
| `DB_HOST` e `DB_PORT` | String | Endereço e porta do RDS | Terraform |
| `DB_DATABASE` e `DB_USERNAME` | String | Banco e usuário do `main.tf` | Terraform |
| `MYSQL_ATTR_SSL_CA` | String | `/etc/ssl/certs/rds-global-bundle.pem`, o bundle que a imagem traz | Terraform |
| `REDIS_HOST` e `REDIS_PORT` | String | `tls://<endereço do primário>` e `6379` | Terraform |
| `CACHE_STORE` e `SESSION_DRIVER` | String | `redis` | Terraform |
| `SESSION_SECURE_COOKIE` | String | `true` com `certificate_arn` (HTTPS no ALB); `false` sem ele | Terraform |
| `TRUSTED_PROXIES` | String | A faixa da VPC (`vpc_cidr`), onde fica o ALB | Terraform |
| `APP_MAINTENANCE_DRIVER` e `APP_MAINTENANCE_STORE` | String | `cache` e `redis` | Terraform |
| `RUN_MIGRATIONS` | String | `true` | Terraform |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` e `MAIL_PORT` | String | Variáveis `mail_mailer`, `mail_from_address` e `mail_port` | Terraform |
| `MAIL_HOST` | String | Variável `mail_host`; só existe com o provedor escolhido | Terraform |
| `MAIL_USERNAME` e `MAIL_PASSWORD` | SecureString | Usuário e senha do provedor de e-mail | Operador, à mão (veja abaixo) |

- "Terraform" quer dizer que o próximo `apply` desfaz uma mudança feita à mão.
- `APP_KEY` e `image_tag` o Terraform cria com o valor provisório e não mexe mais. O `APP_KEY` vai pelo atributo write-only `value_wo`, mandado uma vez só (veja "Preencher o APP_KEY"), e a `image_tag` tem `ignore_changes`.
- Nenhum parâmetro usa o atributo `value`, que guarda no state o valor lido da AWS, inclusive o de um SecureString. O teste confere.
- `DB_CONNECTION` e `APP_NAME` não estavam na lista do plano. Sem o primeiro, o Laravel cai no sqlite (o padrão do `config/database.php`); sem o segundo, assina os e-mails como "Laravel".
- `APP_URL`: sem domínio (`app_url = ""`), o Terraform grava `http://<DNS do ALB>` (a saída `app_url` mostra o mesmo valor). Com domínio, grava o `app_url`. As variáveis exigem `https://` junto com o `certificate_arn` e recusam um sem o outro: sem certificado não existe a porta 443, e com certificado o endereço precisa ser o domínio dele.
- `MAIL_HOST`: o SSM não aceita parâmetro vazio. Com `mail_host = ""` (provedor ainda não escolhido), o `MAIL_HOST` não é criado e o e-mail não sai.
- `SESSION_SECURE_COOKIE` segue o certificado: `true` com `certificate_arn`, `false` sem ele. Com `true` sem HTTPS, o navegador não mandaria o cookie de sessão, e o login não seguraria.
- `TRUSTED_PROXIES`: a faixa da VPC, onde fica o ALB. A aplicação (`config/barberflow.php`) só aceita o `X-Forwarded-For` e o `X-Forwarded-Proto` que chegam dessa faixa: o IP real do visitante (limites por IP) e o HTTPS (cookie seguro e link de confirmação de e-mail) vêm deles. `SANCTUM_STATEFUL_DOMAINS` e `TRUSTED_HOSTS` não são precisos: a aplicação já aceita o host do `APP_URL` e os IPs privados do health check do ALB.
- E-mail: o `mail_mailer` aceita `smtp` (o provedor do grupo) ou `array` (descarta os e-mails, só para teste). O `log` fica de fora, porque gravaria no CloudWatch os links de confirmação de e-mail e de troca de senha. O servidor e a porta do provedor vêm das variáveis `mail_host` e `mail_port`; a porta é a mesma que a saída do app abre no `sg.tf`. O usuário e a senha do provedor são segredos e entram à mão no mesmo caminho. O `user_data` lê junto com o resto, e o Terraform não apaga parâmetro que ele não criou:

```powershell
aws ssm put-parameter --name /barberflow/prod/MAIL_USERNAME --type SecureString --value <usuário do provedor>
aws ssm put-parameter --name /barberflow/prod/MAIL_PASSWORD --type SecureString --value <senha do provedor>
```

### Preencher o APP_KEY

Uma vez por ambiente, antes do primeiro deploy, com o perfil do grupo. No PowerShell, na raiz do repositório:

```powershell
docker build -f docker/php/Dockerfile --target app -t barberflow-app:local .
$appKey = docker run --rm --entrypoint php barberflow-app:local artisan key:generate --show --no-ansi
aws ssm put-parameter --name /barberflow/prod/APP_KEY --type SecureString --value $appKey --overwrite
Remove-Variable appKey
```

1. Faz o build da imagem `app`, que é onde o `artisan` mora. Se já tiver a `barberflow-app:homolog` do `docs/nuvem/DOCKER.md`, pule este passo e use ela nos próximos.
2. Gera a chave na variável `$appKey`, sem mostrar na tela.
3. Grava no SSM por cima do valor provisório. O Terraform não desfaz: ele só manda o `value_wo` de novo se o `value_wo_version` do `ssm.tf` mudar.
4. Apaga a variável da janela do terminal.

- Guarde a chave num cofre de senhas do grupo. Se ela se perder, as sessões abertas caem e o que a aplicação criptografou com ela não abre mais.
- Cada ambiente tem a sua chave: nunca copie a da homolog para a prod.
- Enquanto o parâmetro tiver o valor provisório, o entrypoint de produção recusa a chave (`APP_KEY inválido`, porque o Laravel precisa de 32 bytes) e o container `app` não sobe. O health check do ALB falha e o problema aparece no deploy, em vez de a instância ficar no ar com o `/up` respondendo 200 e toda tela dando 500.
- A chave não vai para o state nem para os planos salvos (`*.tfplan`): o `APP_KEY` é gravado pelo atributo write-only `value_wo`, e o provider não guarda no state o valor que ele lê da AWS no `refresh`. A chave fica só no SSM e no cofre do grupo. Não troque o `value_wo` por `value`: com ele, cada `refresh` traria a chave para o state, e quem lesse o state ou um plano salvo poderia assinar links do Laravel, como o de confirmação de e-mail.
- Para trocar a chave depois, repita os passos e faça o instance refresh (seção "Trocar a capacidade e as instâncias"): as instâncias leem o SSM só quando sobem.

### Variáveis novas

| Variável | Padrão | Homolog | Prod | Para que serve |
|---|---|---|---|---|
| `db_deletion_protection` | `true` | `false` | `true` | Proteção contra exclusão do RDS |
| `db_multi_az` | `false` | `false` | `true` | Réplica de espera do RDS em outra zona |
| `cache_node_type` | `cache.t4g.micro` | igual | igual | Tipo de nó do cache |
| `cache_num_cache_clusters` | `1` | `1` | `2` | Nós do cache, de 1 a 6 (primário e réplicas) |
| `cache_automatic_failover` | `false` | `false` | `true` | Failover e Multi-AZ do cache; precisa de 2 nós ou mais |
| `ecr_keep_tagged_images` | `10` | `10` | `10` | Imagens com tag guardadas em cada repositório, de 2 a 100 |
| `log_retention_days` | `30` | `30` | `30` | Dias de log no CloudWatch, só nos prazos que ele aceita |
| `app_url` | vazio | vazio | vazio | Endereço público da aplicação; vazio sem domínio |
| `mail_mailer` | `smtp` | `smtp` | `smtp` | Transporte de e-mail: `smtp` ou `array` |
| `mail_from_address` | `nao-responda@example.com` | igual | igual | Remetente; troque por um endereço do domínio verificado no provedor |
| `mail_host` | vazio | vazio | vazio | Servidor SMTP do provedor (`MAIL_HOST`); vazio enquanto o grupo não escolher o provedor |
| `mail_port` | `587` | `587` | `587` | Porta SMTP do provedor (`MAIL_PORT`) e a porta que a saída do app abre; recusa a 25, que a AWS bloqueia nas instâncias EC2, e a 443 |

Os padrões são os seguros (banco com proteção contra exclusão). A homolog desliga a proteção no `.tfvars`, para o ambiente poder ser destruído depois da apresentação.

## Computação

Parte do Manoel, na branch `feat/infra-computacao`: troca a EC2 solta da Sprint 1 por um Auto Scaling atrás de um ALB. Como o resto do Terraform, está validada só no código: `validate`, `test` com o provider simulado, `trivy config`, `shellcheck` do `user_data` e a montagem do `.env` num container do Amazon Linux 2023 (seção "Como o user_data foi testado").

O caminho de uma requisição: internet, ALB (porta 80 ou 443), target group, porta 80 de uma instância (Nginx do container `web`), PHP-FPM do container `app` e, nas subnets privadas, o banco e o cache.

### ALB (`alb.tf`)

| O quê | Como está | Por quê |
|---|---|---|
| Tipo | `internet-facing`, nas 2 subnets públicas, com o security group do ALB | É a porta de entrada da aplicação e o único que recebe da internet |
| Target group | HTTP na porta 80 das instâncias (`target_type = instance`) | O Nginx do container `web` escuta na 80 |
| Health check | `GET /up`, esperando `200`, a cada 15 s, com 5 s de limite. A instância entra depois de 2 respostas boas e sai depois de 3 falhas | O `/up` é raso: não consulta banco nem cache, e uma queda do banco não faz o Auto Scaling trocar instâncias em loop |
| Saída de uma instância | Espera de 30 s (`deregistration_delay`) | As requisições em andamento terminam; o padrão de 300 s só atrasaria o instance refresh |
| Porta 80 | Sem certificado, encaminha para o app. Com `certificate_arn`, só redireciona (301) para a 443 | |
| Porta 443 | Só existe com `certificate_arn`, com a política `ELBSecurityPolicy-TLS13-1-2-Res-PQ-2025-09` | TLS 1.3 e 1.2, só cifras com sigilo futuro e troca de chaves pós-quântica. É a padrão do console da AWS (conferido em 13/11/2026); a `ELBSecurityPolicy-TLS13-1-2-2021-06` é a anterior, com mais cifras no TLS 1.2 |
| Cabeçalhos inválidos | Descartados (`drop_invalid_header_fields`) | Cabeçalho com nome fora do padrão não chega à aplicação |
| Proteção contra exclusão | `alb_deletion_protection`: `true` na prod, `false` na homolog | Na prod, nem o `terraform destroy` apaga o ALB |
| Access logs | Desligados | Pediriam um bucket S3 só para eles. O Trivy 0.76.2 não acusa |

### Launch template e user_data (`compute.tf` e `templates/user_data.sh.tftpl`)

| O quê | Como está | Por quê |
|---|---|---|
| AMI | Amazon Linux 2023 x86_64 mais nova, lida do parâmetro público `/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64` | A AWS mantém o parâmetro. Quando sai uma AMI nova, o `apply` seguinte cria uma versão nova do launch template, e o Auto Scaling troca as instâncias |
| Tipo | `instance_type`, `t3.micro` por padrão | Só x86_64: os Graviton (arm64, como `t4g`) pediriam AMI e imagens arm64 |
| Rede | IP público, só o security group do app e nenhuma chave SSH | Sem NAT Gateway (custo por hora), a instância sai para a internet pelo IP público. Ninguém de fora entra: o security group só aceita a 80 vinda do ALB, e o acesso é pelo Session Manager |
| Metadados (IMDS) | IMDSv2 obrigatório (`http_tokens = required`), com hop limit 1 | Só o host lê os metadados e as credenciais da role. Os containers ficam um salto depois e não alcançam; nenhum precisa, porque quem escreve no CloudWatch é o Docker do host |
| Disco | gp3 criptografado, com `root_volume_size` GB (30 por padrão), apagado junto com a instância | Sistema, Docker e imagens. Os logs vão para o CloudWatch, e o banco e o cache ficam fora da instância |
| Permissões | Instance profile `barberflow-<ambiente>-ec2` (`iam.tf`) | ECR, CloudWatch Logs, SSM e Session Manager, com o mínimo |
| Tags | `local.tags` e `Name = barberflow-<ambiente>-app` nas instâncias e nos discos (`tag_specifications`) | O `default_tags` do provider não chega a eles |

O `user_data` roda uma vez, como root, quando a instância nasce, e grava o que fez em `/var/log/barberflow-bootstrap.log`. Os comandos de rede (dnf, download, SSM, ECR) tentam até 5 vezes.

1. Instala o Docker, o `jq` e o `gzip` pelo `dnf` e liga o Docker.
2. Instala o plugin Docker Compose v5.7.1 em `/usr/local/lib/docker/cli-plugins/docker-compose`. O binário `docker-compose-linux-x86_64` vem da release do GitHub e só é instalado se o SHA-256 bater com o do `checksums.txt` da release, gravado no `compute.tf`. O AL2023 não traz o plugin no `dnf`.
3. Grava o `/etc/docker/daemon.json` com o driver `awslogs` e reinicia o Docker: grupo `app`, região do ambiente, `awslogs-create-group` desligado (o grupo nasce no `logs.tf`, com retenção, e a role não cria grupo), stream `<ID da instância>/<nome do container>` e modo `non-blocking` (se o CloudWatch demorar, o log espera num buffer de 4 MB em vez de travar a aplicação; o que passar disso se perde).
4. Monta o `/opt/barberflow/.env` (modo 600, só o root lê) com os parâmetros do SSM do ambiente (abaixo).
5. Entra no ECR (`aws ecr get-login-password | docker login`), grava o `docker-compose.prod.yml` em `/opt/barberflow` (ele vai dentro do `user_data`, compactado), baixa as imagens, apaga o login do ECR do disco (`docker logout`) e sobe `app`, `web` e `scheduler` com `docker compose up --detach --wait`.

O `.env` da instância:

- Cada parâmetro de `/barberflow/<ambiente>/` vira uma linha `NOME='valor'`. O valor vai entre aspas simples, com `\'` no lugar de cada aspa simples: o compose não interpreta `$` dentro delas, e `=`, `#`, aspas duplas, espaços e quebras de linha ficam como estão.
- A `image_tag` não vira linha: vira `APP_IMAGE` e `WEB_IMAGE` (`<repositório do ECR>:<tag>`). Vazia ou com o valor provisório (`PREENCHER_PELO_DEPLOY`), a subida para com "nenhum deploy feito ainda".
- O `user_data` acrescenta `AWSLOGS_REGION`, `AWSLOGS_WEB_GROUP` e `INSTANCE_ID`, que o `logging:` do `web` usa.
- A subida para, citando só o nome do parâmetro (nunca o valor), quando o nome não serve de variável de ambiente (ex.: `MAIL.HOST`, ou um parâmetro numa pasta dentro do caminho, como `/barberflow/prod/mail/USERNAME`), quando repete uma das chaves acima ou quando o valor tem o que esse formato não representa: barra invertida logo antes de uma aspa simples, ou no fim do valor. A senha do RDS do `main.tf` foi conferida e cabe no formato.

Mais sobre o `user_data`:

- Nada de segredo no `user_data` nem no launch template: a senha do banco, o `APP_KEY` e as credenciais do e-mail vêm do SSM na subida, pela role da instância. O `tests/computacao.tftest.hcl` confere que o template não cita a senha do RDS e que o texto pronto não traz senha nem chave.
- A AWS aceita até 16 KB de `user_data`. Hoje ele tem perto de 13 KB, com o compose compactado, e uma pré-condição do launch template para o `plan` se passar do limite.
- Ele só roda na primeira subida. Para uma instância pegar um parâmetro novo do SSM ou uma imagem nova, ela precisa ser trocada (instance refresh). Mudança no `user_data`, no `docker-compose.prod.yml` ou na AMI cria uma versão nova do launch template, e o próprio `apply` começa o instance refresh.
- **Mudança no `docker-compose.prod.yml` só chega às instâncias pelo `apply`.** O compose vai dentro do `user_data` da versão do launch template, e o deploy da `feat/ci-cd` troca só a imagem (`image_tag` e instance refresh), sem `apply`. Uma versão que muda o compose (variável nova, healthcheck, comando ou serviço) segue esta ordem: primeiro o `apply`, que já troca as instâncias com a imagem que está no ar, e depois o deploy da imagem nova. Por isso o compose novo precisa funcionar também com a imagem anterior, o que vale igual para o rollback, que sobe a imagem antiga com o compose atual.
- O SHA-256 do compose embutido fica na tag `ComposeFileSha256` do launch template e na saída `compose_file_sha256`. O deploy compara com o `docker-compose.prod.yml` do commit e para quando são diferentes, sinal de que faltou o `apply` (veja "Para a `feat/ci-cd`").

### Auto Scaling (`compute.tf`)

| O quê | Homolog | Prod | Por quê |
|---|---|---|---|
| Mínimo, desejado e máximo | 1, 1 e 2 | 1, 2 e 4 | Na prod, uma instância em cada zona. O mínimo e o desejado aceitam 0 para o primeiro deploy |
| Subnets | As 2 públicas, uma em cada zona | igual | Se uma zona cair, a outra segura |
| Health check | ELB (o `/up` do ALB), com 300 s de carência | igual | Instância que não responde é trocada; os primeiros 300 s são do `user_data` (Docker, imagens e migrations) |
| Troca de instâncias | Instance refresh `Rolling`, com pelo menos 50% no ar | igual | Na prod, troca uma de cada vez. Na homolog, com 1 instância, a única sai antes da nova ficar pronta, e o app fica fora do ar alguns minutos |
| Escala | CPU média (`ASGAverageCPUUtilization`), com alvo de 60% (`asg_cpu_target`) | igual | Acima do alvo cria instância, abaixo remove, sempre entre o mínimo e o máximo |
| Aquecimento | 300 s (`default_instance_warmup`) | igual | Instância nova não entra na média de CPU nem conta no instance refresh enquanto sobe |
| Métricas do grupo | Desejado, em serviço, pendentes e outras, a cada minuto, sem custo | igual | Para os alarmes e o dashboard da `feat/observabilidade` |
| Tags | `local.tags` e `Name`, passadas às instâncias | igual | O `default_tags` não chega ao Auto Scaling |

- O Auto Scaling usa a versão exata do launch template, e não `$Latest`: uma versão nova muda o Auto Scaling, e o Terraform começa o instance refresh sozinho.
- O desejado fica no Terraform: o `apply` devolve o desejado ao valor da variável, mesmo que a escala pela CPU tenha mudado. Em poucos minutos a escala ajusta de novo (seção "Trocar a capacidade e as instâncias").

### Orçamento (`budget.tf`)

- Orçamento mensal de custo, com limite em `budget_limit_usd` (US$ 30 por padrão). Avisa por e-mail quando o gasto real do mês passa de 80% do limite e quando a previsão da AWS para o fim do mês passa de 100%.
- Só é criado com e-mail em `budget_alert_email`. Sem ninguém para avisar, não serviria para nada.
- Olha a conta inteira, e não só o ambiente: assim pega também o que alguém esqueceu ligado fora do Terraform. Com homolog e prod na mesma conta, preencha o e-mail num ambiente só (o exemplo deixa para o `prod.tfvars`), senão o mesmo aviso chega duas vezes.
- Acompanhar o orçamento e receber os avisos não custa nada (preço do AWS Budgets conferido em 13/11/2026). Ele só avisa, não desliga nada.
- As instâncias `t3` sobem no modo `unlimited`, o padrão da AWS para essa família: com a CPU alta por muito tempo, a AWS cobra os créditos extras. É mais um motivo para o aviso.

### Variáveis da computação

| Variável | Padrão | Homolog | Prod | Para que serve |
|---|---|---|---|---|
| `certificate_arn` | vazio | vazio | vazio | Certificado do ACM para o HTTPS do ALB, na mesma região. Com ele, o `app_url` precisa ser `https://<domínio>` |
| `alb_deletion_protection` | `true` | `false` | `true` | Proteção contra exclusão do ALB |
| `instance_type` | `t3.micro` | igual | igual | Tipo das instâncias, só x86_64 |
| `root_volume_size` | `30` | `30` | `30` | Disco das instâncias, de 20 a 100 GB |
| `asg_min_size` | `1` | `1` | `1` | Mínimo de instâncias; 0 só no primeiro deploy |
| `asg_desired_capacity` | `1` | `1` | `2` | Instâncias no ar, entre o mínimo e o máximo; 0 só no primeiro deploy |
| `asg_max_size` | `2` | `2` | `4` | Teto da escala e do custo, de 1 a 10 |
| `asg_cpu_target` | `60` | `60` | `60` | CPU média (%) que a escala persegue, de 20 a 90 |
| `budget_limit_usd` | `30` | `30` | `30` | Limite do orçamento mensal, em dólares |
| `budget_alert_email` | vazio | vazio | vazio (preencha com o e-mail do grupo) | Quem recebe os avisos; vazio, o orçamento não é criado |

Os padrões seguem a ideia dos anteriores: ALB com proteção contra exclusão e capacidade pequena. O `app_url` (dos dados) e o `certificate_arn` andam juntos: as variáveis recusam domínio em `https://` sem certificado e certificado sem domínio em `https://`.

## Antes de começar

- Docker Desktop. A imagem `hashicorp/terraform:1.13.3` roda o Terraform sem instalar nada. Quem já tem o Terraform 1.11 ou mais novo instalado pode usar os mesmos comandos sem o `docker run`.
- Validar e testar não precisam de conta da AWS nem de credencial.
- Aplicar precisa da conta do grupo (veja "Aplicar na AWS").

## Validar e testar sem conta da AWS

No PowerShell, na raiz do repositório, crie a função `tf`. Ela vale só nessa janela do terminal:

```powershell
function tf { docker run --rm -v "${PWD}\terraform:/repo/terraform" -v "${PWD}\docker-compose.prod.yml:/repo/docker-compose.prod.yml:ro" -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /repo/terraform hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 @args }
```

O container enxerga a pasta `terraform/` e, só para leitura, o `docker-compose.prod.yml` da raiz, que o `user_data` leva dentro dele (`compute.tf`, com `file("${path.module}/../docker-compose.prod.yml")`). Sem esse arquivo, `validate`, `test` e `plan` param com `Invalid value for "path" parameter: no file exists at "./../docker-compose.prod.yml"`, apontando para a linha do `compose_file_gzip_base64` no `compute.tf`. Por isso, desde a `feat/infra-computacao`, um comando que monta só a pasta `terraform/` (`-v ...\terraform:/tf -w /tf`) não serve mais: use a função `tf` abaixo, e o CI roda com o repositório inteiro. O resto do repositório (o `.env`, por exemplo) fica fora do container.

A imagem vai fixada por digest (o `@sha256:...` depois da tag), como a do Trivy. A tag é só um nome, que quem publica a imagem, ou um invasor com acesso à conta, pode apontar para outro conteúdo; o caso do Trivy, na seção "Trivy", mostra o estrago. Com o digest, o Docker só roda a imagem com aquele conteúdo exato. O digest acima é o da `hashicorp/terraform:1.13.3`, conferido em 13/11/2026. Ele serve no Docker Desktop do Windows e do Mac (Intel e Apple Silicon) e no Linux, porque aponta para a lista com as imagens de cada plataforma.

Depois rode:

```powershell
tf fmt -check -recursive
tf init -backend=false
tf validate
tf test
tf -chdir=bootstrap init -backend=false
tf -chdir=bootstrap validate
tf -chdir=bootstrap test
```

1. `fmt -check -recursive` confere a formatação de todas as pastas, sem mudar nada. Para corrigir, rode `tf fmt -recursive`.
2. `init -backend=false` baixa o provider na versão do lock e não toca no S3. O provider (perto de 770 MB) fica no volume `barberflow-tf-plugins` e é baixado uma vez só; a pasta `terraform/.terraform` só aponta para ele e fica fora do Git.
3. `validate` confere sintaxe, tipos e referências.
4. `test` roda os testes com o provider AWS simulado (`mock_provider`): o Terraform finge criar os recursos, sem conta e sem custo.
5. O `-chdir=bootstrap` faz o mesmo na stack do bucket do state.

O `test` termina com `Success! 45 passed, 0 failed.` na raiz (18 da computação, 19 dos dados e da segurança e 8 da rede) e `Success! 1 passed, 0 failed.` no bootstrap.

No Git Bash, a função fica assim (o `MSYS_NO_PATHCONV=1` impede o Git Bash de trocar o `/repo/terraform` por um caminho do Windows):

```bash
tf() { MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/terraform:/repo/terraform" -v "$(pwd -W)/docker-compose.prod.yml:/repo/docker-compose.prod.yml:ro" -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /repo/terraform hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 "$@"; }
```

Para rodar só um arquivo de teste: `tf test "-filter=tests/computacao.tftest.hcl"` (as aspas são obrigatórias no PowerShell, veja "Escolher o ambiente").

### O que os testes conferem

O provider simulado devolve o que a configuração pede e inventa só o que a AWS calcularia (IDs, por exemplo). Os testes conferem a configuração, não o comportamento da AWS.

`terraform/tests/rede.tftest.hcl`:

- 2 subnets públicas e 2 privadas, as públicas em zonas diferentes e as privadas também;
- as faixas de IP de cada subnet e as zonas usadas;
- o filtro que deixa só zonas de disponibilidade na lista (sem Local Zone nem Wavelength Zone);
- nenhuma subnet dando IP público sozinha;
- VPC com DNS ligado;
- tabela pública com `0.0.0.0/0` para o internet gateway, tabela privada sem rota inline além da local, e cada subnet ligada à tabela certa;
- nenhum `aws_route` avulso nos `.tf` da pasta. O provider simulado mostra só as rotas escritas dentro da tabela, então esse assert lê o texto dos arquivos; um `aws_route` só em comentário não conta;
- security group padrão sem regra;
- nome da VPC e tags padrão;
- a prod com 3 zonas e a faixa `10.30.0.0/16`;
- a menor VPC aceita (`/19`) com subnets `/27`, o mínimo do ALB;
- recusa ambiente fora de homolog e prod, `az_count` 1, faixa de IP que não começa no endereço de rede, VPC menor que `/19` e mais zonas do que a região tem.

`terraform/tests/dados_seguranca.tftest.hcl`:

- security groups: só o ALB recebe da internet (80 e 443, em IPv4 e IPv6); o app só do ALB, na 80; o banco só do app, na 3306; o cache só do app, na 6379; nenhuma entrada na porta 22; banco e cache sem saída; o ALB só sai para o app; o app sai só na 443 e na porta do SMTP para a internet, na 3306 para o banco e na 6379 para o cache; descrições sem acento;
- duas travas que leem o texto dos `.tf` da pasta, porque os asserts só enxergam o que está nas listas do teste. A primeira exige a contagem exata de security groups (4), de recursos de regra de entrada (4) e de saída (4). A segunda falha se achar regra escrita dentro de um security group (bloco `ingress { }`, `dynamic "ingress"` ou atributo `ingress = [{ ... }]`, e o mesmo para `egress`) ou o `aws_security_group_rule` antigo; só a lista vazia do security group padrão (`ingress = []`) passa. Um assert confere o mesmo pelo plano: nenhum dos 4 security groups tem regra dentro dele;
- IAM: só o EC2 assume a role; o instance profile usa a role; a `AmazonSSMManagedInstanceCore` está ligada; nenhuma ação com curinga e nenhum `Resource "*"` fora do login no ECR; cada permissão presa aos seus recursos (2 repositórios, 2 log groups, caminho do SSM do ambiente e a chave `aws/ssm` pelo `kms:ViaService`); a negação dos parâmetros de outros ambientes; e uma trava que lê o texto dos `.tf` e falha se outro recurso se ligar à role (só a política própria, o anexo da `AmazonSSMManagedInstanceCore` e o instance profile citam `aws_iam_role.ec2`) ou se aparecer `managed_policy_arns` ou `inline_policy`;
- ECR: nomes, tags imutáveis, scan no push, AES256 e as duas regras de limpeza;
- logs: nomes e retenção dos 4 log groups;
- RDS: disco gp3 criptografado, sem acesso público, nas subnets privadas, só com o security group do banco, 7 dias de backup com as janelas, snapshot final, proteção contra exclusão e Multi-AZ seguindo as variáveis, MySQL 8.4, logs de erro e de consultas lentas, parameter group com TLS obrigatório e `log_output = FILE`, e sem `manage_master_user_password`;
- a senha do banco escrita só no `main.tf`: uma trava lê os outros `.tf` (pula o `main.tf`) e confere que o `ssm.tf` passa a senha por referência;
- cache: Valkey 9.1, criptografia em repouso e em trânsito, subnets privadas, security group do cache, um nó na homolog e réplica com failover na prod;
- SSM: a lista exata de parâmetros, `DB_PASSWORD` e `APP_KEY` como SecureString e o resto como String, os valores (bloco de produção, banco, cache com `tls://`, sessão, manutenção e e-mail), o `APP_URL` com o domínio ou, sem ele, com o DNS do ALB, o `MAIL_HOST` só com provedor, a saída SMTP do app na mesma porta do `MAIL_PORT`, os valores provisórios (`image_tag` nunca `latest`), o `DB_PASSWORD` e o `APP_KEY` pelo `value_wo` e nenhum parâmetro do `ssm.tf` com o atributo `value`;
- as saídas, a prod (nomes e permissões com `prod`) e os padrões das variáveis;
- recusa failover com 1 nó, cache sem nó, tipo de nó inválido, retenção de log que o CloudWatch não aceita, guardar só 1 imagem, `app_url` com barra ou caminho no fim, `mail_mailer = "log"`, remetente sem `@`, `mail_host` com protocolo e `mail_port` 25.

Cada regra desse arquivo foi conferida quebrando a configuração de propósito numa cópia da pasta (banco e app abertos para a internet por regra escrita dentro do security group, saída do app com todos os protocolos, RDS sem criptografia, ação com curinga, `AdministratorAccess` anexada à role, `APP_KEY` como String ou pelo `value`, `image_tag` com `latest` e outros): em todos os casos o teste falhou.

A `feat/infra-computacao` mexeu em três pontos desse arquivo e no `rede.tftest.hcl`, sem afrouxar nenhuma regra: o `mock_provider` dos dois ganhou o ARN do ALB e do target group e o ID do launch template (o provider confere o formato, e o texto que o mock inventa não passaria); o run `ssm_parameters` ganhou o `certificate_arn`, porque domínio em `https://` agora exige certificado, e por isso espera o cookie de sessão em `true`; e a lista de parâmetros e o run `defaults_without_domain` passaram a esperar o `TRUSTED_PROXIES` e o `APP_URL` com o DNS do ALB.

`terraform/tests/computacao.tftest.hcl`:

- launch template: AMI do parâmetro público do Amazon Linux 2023, `t3.micro`, IMDSv2 obrigatório com hop limit 1, disco gp3 criptografado de 30 GB, instance profile da role e tags nas instâncias e nos discos;
- instância sem porta aberta para a internet: só o security group do app (com IP público, sem chave SSH), e esse security group só recebe a 80, só do ALB;
- `user_data`: é o do template, em bash com `set -euo pipefail`, abaixo de 16 KB, lê `/barberflow/<ambiente>` do SSM com `--recursive` e `--with-decryption`, usa os repositórios do ECR, os log groups e o valor provisório da `image_tag`, instala o compose na versão fixa conferida pelo SHA-256 e leva o `docker-compose.prod.yml` do repositório;
- nenhum segredo no `user_data`: o template não cita a senha do RDS, o `compute.tf` não passa a senha ao template, e o texto pronto não contém a senha (a conferência devolve só verdadeiro ou falso, sem mostrar o valor), o `APP_KEY` provisório, chave do Laravel, chave de acesso da AWS nem chave privada;
- Auto Scaling: nas 2 subnets públicas (2 zonas), health check ELB com 300 s, no target group, capacidade da homolog (1, 1 e 2) e da prod (1, 2 e 4), versão exata do launch template, instance refresh `Rolling` com 50%, tags com `propagate_at_launch` e a escala pela CPU com alvo de 60%;
- ALB: público, nas subnets públicas, com o security group do ALB, `drop_invalid_header_fields` e proteção contra exclusão; target group HTTP 80 com health check no `/up` e 30 s de saída;
- portas: sem certificado, a 80 encaminha e a 443 não existe; com certificado, a 80 redireciona (301) para a 443, que usa o certificado e uma política TLS 1.3 e 1.2;
- `APP_URL` e `SESSION_SECURE_COOKIE`: DNS do ALB e `false` sem domínio; `https://<domínio>` e `true` com domínio e certificado; o `app_url` e `false` com domínio sem certificado; `TRUSTED_PROXIES` com a faixa da VPC;
- orçamento: não existe sem e-mail; com e-mail, mensal, de custo, US$ 30, com os avisos a 80% do real e 100% do previsto;
- saídas `alb_dns_name`, `app_url`, `asg_name`, `launch_template_id` e `compose_file_sha256`, que é o SHA-256 do `docker-compose.prod.yml` e o mesmo valor da tag `ComposeFileSha256` do launch template;
- nenhuma `aws_instance` nem `aws_launch_configuration` nos `.tf` da pasta e do `bootstrap/` (uma trava que lê o texto dos arquivos);
- o primeiro deploy com mínimo e desejado 0;
- recusa certificado sem domínio em `https://`, domínio em `https://` sem certificado, ARN de certificado inválido ou de outra região, tipo Graviton, disco menor que 20 GB, desejado fora do mínimo e do máximo, máximo 0 ou acima de 10, alvo de CPU fora de 20 a 90, e-mail do orçamento inválido e limite 0.

As regras desse arquivo também foram conferidas quebrando a configuração numa cópia da pasta, em 28 defeitos (IMDSv1, hop limit 2, disco sem criptografia ou gp2, sem IP público, instância com o security group do ALB, Auto Scaling numa zona só, health check EC2, fora do target group, `$Latest`, refresh com 90%, tags sem propagar, ALB interno ou sem `drop_invalid_header_fields`, health check fora do `/up`, 80 sempre encaminhando, 443 sempre ligada, TLS antigo, `APP_URL` e cookie fixos, `TRUSTED_PROXIES` com `*`, `user_data` sem `--with-decryption` ou com a senha do RDS, SHA-256 trocado, EC2 solta de volta e o orçamento sempre criado ou com aviso a 90%): o teste falhou em todos.

`terraform/bootstrap/tests/state.tftest.hcl`: nome do bucket com o ID da conta, versionamento, criptografia, as quatro travas de acesso público, a política que nega acesso sem HTTPS (no bucket e nos objetos) e o bucket sem `force_destroy`.

### Como o user_data foi testado

O `user_data` só roda de verdade numa instância, depois do primeiro `apply`. Antes disso, duas conferências rodam no computador, com o script pronto. Os arquivos ficam em `terraform/tests/user_data/`:

| Arquivo | Para que serve |
|---|---|
| `render.sh` | Preenche o template como o `templatefile` do `compute.tf`, com valores de exemplo da homolog, e escreve o script pronto na saída. A versão e o SHA-256 do compose vêm do `compute.tf`, e a `image_tag` provisória vem do `ssm.tf`. Para com erro se sobrar marcador sem valor ou se o template ganhar uma diretiva `%{ }`, que ele não sabe preencher: aí o `render.sh` precisa mudar junto |
| `env_test.sh` | Teste da montagem do `.env`, num container descartável do Amazon Linux 2023 (ele se recusa a rodar fora de um) |
| `ssm_parameters.json` | Resposta de exemplo do `get-parameters-by-path`, com 22 parâmetros e a `image_tag`. Os valores são falsos, feitos para quebrar o formato: aspas simples no começo, no fim, sozinhas e em dupla, aspas duplas, `$`, `${...}`, `#`, crase, `=`, `\n` escrito, barra invertida no meio, quebra de linha, tab, espaços nas pontas, acentos e símbolos |

**ShellCheck 0.11.0**, no Git Bash ou no Linux, na raiz do repositório:

```bash
bash terraform/tests/user_data/render.sh | docker run --rm -i koalaman/shellcheck:v0.11.0@sha256:61862eba1fcf09a484ebcc6feea46f1782532571a34ed51fedf90dd25f925a8d --shell=bash --severity=style -
```

Sem aviso, o comando não mostra nada e termina com código 0.

**Montagem do `.env`**, no PowerShell, na raiz do repositório:

```powershell
docker run --rm -v "${PWD}\terraform:/src/terraform:ro" -v "${PWD}\docker-compose.prod.yml:/src/docker-compose.prod.yml:ro" amazonlinux:2023.12.20261102.0@sha256:3ccfbe800f864982b0a4e124a614b2382518cbd04b3c1cd35202bd46f392675b bash /src/terraform/tests/user_data/env_test.sh
```

No Git Bash, troque `${PWD}\` por `$(pwd -W)/` e comece com `MSYS_NO_PATHCONV=1`, como na função `tf`. O container enxerga só a pasta `terraform/` e o compose, os dois só para leitura, e precisa de internet (repositórios do `dnf` e a release do compose no GitHub). Leva menos de um minuto e termina com `Sucesso: 15 conferências passaram.` O teste:

1. instala o `jq` e o `gzip` pelo `dnf`, como a instância;
2. carrega o script pronto com `source`, que só define as funções (a subida inteira roda quando o arquivo é executado, como faz o cloud-init);
3. instala o compose v5.7.1 pela função do próprio `user_data`, que confere o SHA-256;
4. troca o `aws` por um falso, que só aceita a chamada exata do `user_data` (caminho do ambiente, `--recursive` e `--with-decryption`) e devolve o `ssm_parameters.json`;
5. monta o `.env` e grava o compose, e confere: modo 600 e dono root, nenhum arquivo temporário sobrando, nenhuma linha `image_tag`, `APP_IMAGE` e `WEB_IMAGE` na tag certa, compose gravado igual ao do repositório e o `web` mandando os logs para o grupo `web`;
6. confere no `docker compose config` da produção que os 22 valores chegam iguais ao `app` e ao `scheduler`. O `config` mostra cada `$` como `$$`, porque a saída dele também é um compose; o container recebe o `$` simples. Se o compose interpretasse o `$HOME` do `.env`, o `config` mostraria o valor trocado;
7. confere que a subida para, sem mexer no `.env` anterior, com a `image_tag` provisória, ausente ou inválida, com parâmetro de nome reservado, com nome que não serve de variável (com ponto ou numa pasta) e com valor que o formato não representa, e que nesses dois últimos casos o log não mostra o valor.

Cinco defeitos de propósito no template, numa cópia montada por cima do original, fizeram o teste falhar: sem escapar as aspas simples, com aspas duplas no lugar das simples (o compose passa a trocar o `$HOME`), sem parar na `image_tag` provisória, `.env` com modo 644 e sem a trava da barra invertida.

O que o `user_data` faz numa instância de verdade (dnf, IMDS, ECR, awslogs) fica validado só no código até o primeiro deploy.

### Trivy

O `trivy config` procura configuração insegura no Terraform. Na raiz do repositório:

```powershell
docker run --rm -v "${PWD}\terraform:/src:ro" -w /src aquasec/trivy:0.76.2@sha256:8f6bb947dbc528902e11f2f5be52f7b519f6a39d5d148f41166f2040499768fb config --ignorefile .trivyignore.yaml --exit-code 1 .
```

- A imagem vai fixada por digest: o mesmo `sha256` no Docker Hub, no GHCR e no ECR Public, conferido em 13/11/2026. Nunca use a 0.69.4, publicada por um invasor em 19/03/2026, nem as imagens 0.69.5 e 0.69.6 do Docker Hub, publicadas do mesmo jeito em 22/03/2026 (aviso [GHSA-69fq-xp46-6x23](https://github.com/aquasecurity/trivy/security/advisories/GHSA-69fq-xp46-6x23)). Versões antes da 0.72.0 têm outras falhas, já corrigidas.
- Para atualizar, leia as notas da versão nova e os avisos de segurança do Trivy e troque a tag e o digest juntos (`docker buildx imagetools inspect aquasec/trivy:<versão>` mostra o digest).
- O `--ignorefile .trivyignore.yaml` é obrigatório. Sem ele, o Trivy lê o `.trivyignore` da pasta onde roda; o da pasta `terraform/` fica vazio de propósito, então todos os alertas da tabela abaixo aparecem e o comando falha.
- Com o Trivy instalado no computador, rode de dentro da pasta `terraform/` com o mesmo `--ignorefile .trivyignore.yaml`. Da raiz também funciona (`trivy config --ignorefile terraform/.trivyignore.yaml --exit-code 1 terraform`), porque os `paths` valem a partir da pasta analisada.

Os alertas aceitos ficam no `terraform/.trivyignore.yaml`. Cada item tem o ID do alerta, o arquivo onde ele vale (`paths`) e o motivo (`statement`). Como cada aceite fica preso ao arquivo, o alerta continua valendo no resto do projeto: um RDS novo sem login por IAM num arquivo novo é acusado, mesmo com o AWS-0176 aceito no `main.tf`. Um `.trivyignore` comum, só com os IDs, aceitaria o alerta em qualquer arquivo; por isso o da pasta fica vazio.

| Alerta | Onde (`paths`) | Por que fica | Até quando |
|---|---|---|---|
| AWS-0053 | `alb.tf`, ALB | O ALB é público de propósito: é a porta de entrada da aplicação, e só ele recebe da internet | Fica |
| AWS-0054 | `alb.tf`, porta 80 | Sem `certificate_arn` (o padrão, enquanto o grupo não compra um domínio), a 80 encaminha em HTTP. Com o certificado, ela só redireciona para a 443 e o alerta some | Até o grupo ter domínio e certificado |
| AWS-0176 | `main.tf`, RDS | Login por IAM desligado: a aplicação entra com usuário e senha (decisão de 13/11), e o Laravel não gera nem renova o token do IAM, que vence em 15 minutos | Fica |
| AWS-0133 | `main.tf`, RDS | Performance Insights (hoje CloudWatch Database Insights) não roda no `db.t3.micro`; a AWS só aceita a partir do `db.t3.medium`, e o modo Advanced é pago | Fica enquanto a classe for `db.t3.micro` |
| AWS-0104 | `sg.tf`, saída do app | Saída para a internet só na 443 e na porta do SMTP, porque não há NAT Gateway nem VPC endpoints | Fica enquanto não houver um dos dois |
| AWS-0033 | `ecr.tf`, repositórios | AES256 com a chave da AWS, e não uma chave do KMS do grupo (US$ 1 por mês) | Fica |
| AWS-0017 | `logs.tf`, log groups | Criptografia padrão do CloudWatch, e não uma chave do KMS do grupo | Fica |
| AWS-0178 | `network.tf`, VPC | Sem Flow Logs, cortados no plano como excesso | Fica |
| AWS-0132 | `bootstrap/main.tf`, bucket do state | Criptografia SSE-S3 em vez de uma chave do KMS (US$ 1 por mês e mais uma linha no `backend.hcl`) | Fica |
| AWS-0089 | `bootstrap/main.tf`, bucket do state | Sem log de acesso, que pediria um segundo bucket | Fica |

A `feat/infra-dados-seguranca` corrigiu e apagou os itens AWS-0077 (backup curto), AWS-0080 (RDS sem criptografia) e AWS-0177 (sem proteção contra exclusão). A `feat/infra-computacao` apagou os itens AWS-0028 (sem IMDSv2) e AWS-0131 (disco sem criptografia), que eram da EC2 solta do `main.tf`: o launch template exige IMDSv2 e criptografa o disco, e o Trivy passa nele.

Mais três pontos do Trivy na computação:

- Ele avalia o Terraform com os valores padrão das variáveis, ou seja, sem certificado. Com um `.tfvars` que tenha `app_url` em `https://` e `certificate_arn` (opção `--tf-vars`), o AWS-0054 passa, e o AWS-0047 (política TLS antiga) confere a política da 443 e passa também.
- Os access logs do ALB ficam desligados, porque pediriam um bucket S3 só para eles. O Trivy 0.76.2 não tem regra para isso; o motivo fica anotado no `.trivyignore.yaml`, junto dos itens do ALB, para o dia em que uma versão nova acusar.
- O comando acima monta só a pasta `terraform/`. O `user_data` lê o `../docker-compose.prod.yml`, que fica fora do container, e o Trivy segue sem ele; quem confere que o `user_data` não tem segredo é o `tests/computacao.tftest.hcl`.

O Trivy não acusa a senha escrita no `main.tf`: o `trivy config` não tem regra para senha em atributo, e o scanner de segredos (`trivy fs --scanners secret`) procura formatos conhecidos, como chave de acesso da AWS. O risco está descrito em "Senha do RDS".

Há duas coisas que o Trivy não confere nesta pasta, e o `tests/dados_seguranca.tftest.hcl` cobre as duas:

- a política da role das instâncias (`iam.tf`), montada com ARNs que só existem depois do `apply`;
- entrada aberta para a internet em porta que não seja de acesso remoto: na versão 0.76.2, a regra AWS-0107 só acusa SSH (22) e RDP (3389) abertos.

## State remoto

O state é o arquivo em que o Terraform anota tudo o que criou. Ele fica num bucket S3 para o grupo inteiro trabalhar sobre o mesmo state:

- `use_lockfile = true` cria uma trava (um arquivo `.tflock` ao lado do state) enquanto alguém roda `plan` ou `apply`, e quem chega depois espera. Sem a trava, dois `apply` ao mesmo tempo gravam um state por cima do outro, e os recursos de um somem do state;
- o versionamento do bucket guarda as versões antigas do state, para voltar atrás se uma delas estragar;
- o state guarda valores em texto puro, inclusive senhas. Por isso o bucket é privado, criptografado e só aceita HTTPS, e o `encrypt = true` pede a criptografia em cada gravação do state.

A trava e a criptografia ficam escritas no `terraform/backend.tf`, que vai para o Git, e não no `backend.hcl`. Assim valem em todo `init`, mesmo para quem passa o bucket, a key e a região direto no `-backend-config` ou escreve o `backend.hcl` sem copiar o exemplo. O que vem do `init` vale mais que o `backend.tf`, então nunca passe `use_lockfile` nem `encrypt` no `-backend-config`.

Os comandos com `tfaws` desta seção e das duas seguintes precisam da conta do grupo e não foram rodados nesta branch.

### 1. Criar o bucket (uma vez por conta)

Com o perfil do AWS CLI do grupo configurado no seu computador, crie a função `tfaws` (como a `tf`, mais as credenciais e o terminal interativo, para responder ao `apply`):

```powershell
function tfaws { docker run --rm -it -v "${PWD}\terraform:/repo/terraform" -v "${PWD}\docker-compose.prod.yml:/repo/docker-compose.prod.yml:ro" -v "$env:USERPROFILE\.aws:/root/.aws:ro" -e AWS_PROFILE -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /repo/terraform hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 @args }
$env:AWS_PROFILE = "nome-do-perfil-do-grupo"
tfaws -chdir=bootstrap init
tfaws -chdir=bootstrap apply
```

- A pasta `.aws` do Windows entra só para leitura (o `:ro` no fim do `-v`). Sem ele, uma imagem adulterada, que roda como root, poderia gravar ali uma linha `credential_process`, e o AWS CLI do Windows rodaria o comando dessa linha na próxima vez que usasse o perfil.
- O container enxerga as credenciais de todos os perfis da pasta, não só a do grupo. Por isso a imagem vai pelo mesmo digest da `tf`: ninguém troca o que roda com as suas credenciais.
- Se o perfil do grupo entra por SSO, rode `aws sso login --profile nome-do-perfil-do-grupo` no Windows antes. Só leitura, o container não consegue renovar o login vencido.

O `apply` mostra o que vai criar e pede `yes`. No fim aparece o `state_bucket_name`, no formato `barberflow-tfstate-<ID da conta>`.

O state do bootstrap fica em `terraform/bootstrap/terraform.tfstate`, no seu computador (o Git ignora). Guarde uma cópia num lugar do grupo. Se ele se perder, o bucket continua funcionando; só o Terraform deixa de saber que foi ele que o criou.

### 2. Ligar a pasta terraform/ ao bucket

```powershell
Copy-Item terraform\backend.hcl.example terraform\backend.hcl
```

Troque o `bucket` do `terraform/backend.hcl` pelo `state_bucket_name` do passo anterior. O Git ignora esse arquivo. Ele tem só o bucket, a key e a região: a trava e a criptografia já estão no `backend.tf`.

## Escolher o ambiente

Cada ambiente tem o próprio state (a `key` no bucket) e o próprio arquivo de valores (`envs/<ambiente>.tfvars`). Copie os exemplos uma vez:

```powershell
Copy-Item terraform\envs\homolog.tfvars.example terraform\envs\homolog.tfvars
Copy-Item terraform\envs\prod.tfvars.example terraform\envs\prod.tfvars
```

Homologação:

```powershell
tfaws init -reconfigure "-backend-config=backend.hcl" "-backend-config=key=homolog/terraform.tfstate"
tfaws plan "-var-file=envs/homolog.tfvars" "-out=homolog.tfplan"
```

Produção:

```powershell
tfaws init -reconfigure "-backend-config=backend.hcl" "-backend-config=key=prod/terraform.tfstate"
tfaws plan "-var-file=envs/prod.tfvars" "-out=prod.tfplan"
```

- As aspas são obrigatórias no PowerShell: sem elas, ele quebra no ponto o argumento que começa com `-` (o `-var-file=envs/homolog.tfvars` chega ao Terraform como `-var-file=envs/homolog` e `.tfvars`), e o comando falha. No Git Bash as aspas não atrapalham.
- O `"-backend-config=key=..."` vem depois do arquivo e por isso vale mais que a `key` escrita no `backend.hcl`.
- O `-reconfigure` troca de state sem copiar um ambiente para o outro.
- A `key` do `init` e o `-var-file` do `plan` precisam ser do mesmo ambiente. Se o `plan` mostrar a VPC da homolog sendo trocada pela da prod (ou o contrário), pare: os dois estão misturados.
- O `environment` não tem valor padrão. Sem o `-var-file`, o Terraform para pedindo o valor.

## Aplicar na AWS

Aplicar só com a conta do grupo, combinado com todos, porque cria recursos que cobram por hora. Na primeira vez, siga a ordem da seção "Primeiro deploy": o Auto Scaling nasce sem instância, enquanto o ECR não tem imagem.

Aplique sempre o plano salvo, que é exatamente o que foi revisado:

```powershell
tfaws apply homolog.tfplan
```

O plano salvo (`*.tfplan`) guarda valores sensíveis e o Git ignora. Depois da apresentação, `tfaws destroy "-var-file=envs/homolog.tfvars"` apaga o ambiente (com o `init` feito na `key` dele). O bucket do state sai por último, pelo bootstrap, e só depois de esvaziado: com versionamento, isso inclui as versões antigas, que o console do S3 apaga com a opção "Esvaziar".

Quatro recursos travam o `destroy` de propósito:

- **Banco da prod:** com `db_deletion_protection = true`, nem o `destroy` apaga. Mude para `false` no `envs/prod.tfvars`, rode `plan` e `apply`, e só depois o `destroy`.
- **ALB da prod:** com `alb_deletion_protection = true`, o mesmo vale para o ALB. Mude para `false` junto com o banco.
- **Snapshot final:** o `destroy` tira o snapshot `barberflow-<ambiente>-db-final` antes de apagar o banco. Se um snapshot com esse nome já existir (de um `destroy` anterior), o comando para; apague ou renomeie o antigo no console do RDS. O snapshot fica guardado, e cobrando, até alguém apagar.
- **ECR com imagens:** o repositório só sai vazio. Apague as imagens no console do ECR antes. O Terraform não usa `force_delete`, para ninguém perder as imagens sem querer.

## Primeiro deploy

Na primeira vez, o ECR ainda não tem imagem e o parâmetro `image_tag` tem o valor provisório. Uma instância que subisse agora pararia no `user_data` com "nenhum deploy feito ainda", o ALB a daria como doente e o Auto Scaling ficaria trocando instância que não sobe. Por isso o Auto Scaling nasce com 0 instância. Os comandos usam a prod; na homolog, troque `prod` por `homolog`.

1. Crie tudo com a capacidade em 0 (o `-var` vale mais que o `.tfvars`):

   ```powershell
   tfaws plan "-var-file=envs/prod.tfvars" "-var=asg_min_size=0" "-var=asg_desired_capacity=0" "-out=prod.tfplan"
   tfaws apply prod.tfplan
   ```

2. Preencha o `APP_KEY` (seção "Preencher o APP_KEY") e, se o e-mail já tiver provedor, o `MAIL_USERNAME` e o `MAIL_PASSWORD` (seção "Parâmetros do SSM").
3. Publique as imagens e a `image_tag` pelo deploy da `feat/ci-cd` (`deploy.yml`): ele faz o build, manda as imagens `app` e `web` para o ECR com a tag do commit e grava a tag em `/barberflow/prod/image_tag`. Antes de a `feat/ci-cd` existir, dá para fazer à mão, na raiz do repositório:

   ```powershell
   $tag = git rev-parse --short=12 HEAD
   $registry = "<ID da conta>.dkr.ecr.us-east-1.amazonaws.com"
   aws ecr get-login-password --region us-east-1 | docker login --username AWS --password-stdin $registry
   docker build -f docker/php/Dockerfile --target app -t "$registry/barberflow-prod-app:$tag" .
   docker build -f docker/php/Dockerfile --target web -t "$registry/barberflow-prod-web:$tag" .
   docker push "$registry/barberflow-prod-app:$tag"
   docker push "$registry/barberflow-prod-web:$tag"
   aws ssm put-parameter --name /barberflow/prod/image_tag --type String --value $tag --overwrite
   ```

   As tags do ECR são imutáveis: a mesma tag não aceita uma imagem diferente.

4. Suba a capacidade com o plano normal, que usa os valores do `.tfvars` (mínimo 1 e desejado 2 na prod):

   ```powershell
   tfaws plan "-var-file=envs/prod.tfvars" "-out=prod.tfplan"
   tfaws apply prod.tfplan
   ```

5. Confira. Em 3 a 5 minutos as instâncias ficam saudáveis no target group, e o endereço sai no `terraform output app_url`:

   ```powershell
   tfaws output app_url
   aws elbv2 describe-target-health --target-group-arn (aws elbv2 describe-target-groups --names barberflow-prod-app --query "TargetGroups[0].TargetGroupArn" --output text) --query "TargetHealthDescriptions[].TargetHealth.State"
   ```

   Abra o endereço em `/login` e o `/up`. Se as instâncias não ficarem saudáveis, veja "Entrar na instância" e "Logs no CloudWatch".

6. Crie o primeiro administrador (seção "Criar o administrador").

Daí em diante, cada versão nova vem do deploy, que troca a `image_tag` e faz o instance refresh. O Terraform não desfaz a `image_tag` (`ignore_changes` no `ssm.tf`).

## HTTPS com domínio próprio

Sem domínio, a aplicação fica no DNS do ALB, em HTTP (`http://barberflow-prod-alb-<número>.us-east-1.elb.amazonaws.com`). Com um domínio do grupo:

1. Peça um certificado público no ACM, na mesma região do ALB (`us-east-1` nos exemplos), para o domínio (ex.: `barberflow.com.br`), com validação por DNS. O ACM mostra um registro CNAME para criar no DNS do domínio; o certificado fica "Emitido" quando a AWS acha esse registro.
2. No `envs/prod.tfvars`, preencha os dois juntos (as variáveis recusam um sem o outro):

   ```hcl
   app_url         = "https://barberflow.com.br"
   certificate_arn = "arn:aws:acm:us-east-1:<ID da conta>:certificate/<id>"
   ```

3. Rode `plan` e `apply`. A porta 443 passa a existir, a 80 passa a redirecionar (301) para ela, o `APP_URL` vira `https://barberflow.com.br` e o `SESSION_SECURE_COOKIE` vira `true`.
4. No DNS do domínio, crie um registro CNAME (ou ALIAS, no Route 53) apontando para o `alb_dns_name` da saída do Terraform.
5. Faça o instance refresh (seção "Trocar a capacidade e as instâncias"): as instâncias leem o `APP_URL` e o `SESSION_SECURE_COOKIE` só quando sobem.

## Entrar na instância (Session Manager)

As instâncias não têm SSH nem porta aberta. Entra-se pelo Session Manager, com a AWS CLI e o plugin do Session Manager instalados no computador e o perfil do grupo:

```powershell
aws ec2 describe-instances --filters "Name=tag:aws:autoscaling:groupName,Values=barberflow-prod-app" "Name=instance-state-name,Values=running" --query "Reservations[].Instances[].[InstanceId,Placement.AvailabilityZone]" --output text
aws ssm start-session --target <ID da instância>
```

Dentro da instância, vire root e vá para a pasta do app:

```bash
sudo -i
cd /opt/barberflow
docker compose -p barberflow -f docker-compose.prod.yml ps
tail -n 50 /var/log/barberflow-bootstrap.log
```

- O `ps` mostra os três containers (`app`, `web` e `scheduler`), todos `healthy`.
- O `/var/log/barberflow-bootstrap.log` mostra os 5 passos do `user_data` e, se ele parou, o motivo (seção "Problemas comuns").
- O `docker compose logs app` continua funcionando com o `awslogs`: o Docker guarda uma cópia local dos logs recentes.
- Não abra o `.env` na tela (`cat .env`): ele tem a senha do banco e o `APP_KEY`, e a sessão pode estar sendo gravada.
- Se a instância não chega a aparecer no Session Manager (o agente não subiu), o log do cloud-init sai no console: `aws ec2 get-console-output --instance-id <ID> --latest --output text`.

## Criar o administrador

O primeiro admin vem do `AdminUserSeeder` (branch `feat/auth`), com nome, e-mail e senha que só existem no ambiente do comando: nada vai para arquivo, para o SSM nem para o histórico do terminal. Numa instância, pelo Session Manager:

```bash
sudo -i
cd /opt/barberflow
read -rp 'Nome do admin: ' ADMIN_NAME
read -rp 'E-mail do admin: ' ADMIN_EMAIL
read -rsp 'Senha do admin: ' ADMIN_PASSWORD; echo
export ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD
docker compose -p barberflow -f docker-compose.prod.yml run --rm --no-deps -e ADMIN_NAME -e ADMIN_EMAIL -e ADMIN_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=AdminUserSeeder --force
unset ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD
exit
```

- O `read -s` não mostra a senha enquanto ela é digitada, e o que o `read` recebe não entra no histórico do terminal.
- O `-e ADMIN_PASSWORD`, sem `=valor`, passa o valor que está no ambiente: a senha não aparece na linha do comando.
- O `run --rm` sobe um container novo e descartável, e não usa o `exec` no `app` que está no ar: o entrypoint de produção congela a configuração (`php artisan optimize`) quando o container sobe, então o `app` que já está rodando não enxerga variáveis novas. O container descartável congela com o `ADMIN_*`, roda o seeder e é apagado. O `RUN_MIGRATIONS=false` evita que ele rode as migrations de novo.
- Rode uma vez só, numa instância só: o banco é o mesmo para todas. O `AdminUserSeeder` nunca altera um usuário que já existe; sem e-mail ou senha, ele só avisa.
- Depois, entre com esse e-mail em `/login` e troque a senha em Minha conta, se quiser.

## Logs no CloudWatch

| Log group | Streams | O que tem |
|---|---|---|
| `/barberflow/<ambiente>/app` | `<ID da instância>/barberflow-app-1` e `<ID da instância>/barberflow-scheduler-1` | PHP-FPM, Laravel (`LOG_CHANNEL=stderr`) e o agendador |
| `/barberflow/<ambiente>/web` | `<ID da instância>/barberflow-web-1` | Acesso do Nginx, sem query string e sem os tokens dos links, e só as falhas graves do log de erro dele (nível `crit`). Numa falha grave, a linha de erro ainda traz a requisição inteira, com query string e token (veja `docs/nuvem/DOCKER.md`, seção "Nginx") |

Pelo console: CloudWatch, Log groups, o grupo e o stream da instância. Pela AWS CLI (a versão 2 tem o `tail`):

```powershell
aws logs tail /barberflow/prod/app --since 30m --follow
aws logs tail /barberflow/prod/web --since 30m --log-stream-name-prefix <ID da instância>
aws logs tail /barberflow/prod/app --since 1h --filter-pattern ERROR
```

- Os logs ficam `log_retention_days` dias (30 por padrão) e depois somem sozinhos.
- O log da subida da instância (`/var/log/barberflow-bootstrap.log`) não vai para o CloudWatch: o driver `awslogs` só leva os containers. Ele fica na instância (seção "Entrar na instância") e no console (`aws ec2 get-console-output`).
- Os logs de erro e de consultas lentas do banco ficam em `/aws/rds/instance/barberflow-<ambiente>-db/error` e `/slowquery`.

## Trocar a capacidade e as instâncias

**Mudar a capacidade de vez:** troque `asg_min_size`, `asg_desired_capacity` ou `asg_max_size` no `envs/<ambiente>.tfvars` e rode `plan` e `apply`. As variáveis recusam desejado fora do mínimo e do máximo e máximo acima de 10.

**Mudar só por agora**, sem Terraform (entre o mínimo e o máximo):

```powershell
aws autoscaling set-desired-capacity --auto-scaling-group-name barberflow-prod-app --desired-capacity 3
```

O próximo `apply` volta o desejado ao valor do `.tfvars`; depois, a escala pela CPU ajusta de novo em poucos minutos.

**Desligar as instâncias** (ex.: à noite, para economizar): mínimo e desejado 0, pelo `.tfvars` ou com `aws autoscaling update-auto-scaling-group --auto-scaling-group-name barberflow-prod-app --min-size 0 --desired-capacity 0`. O ALB, o banco e o cache continuam cobrando por hora.

**Trocar as instâncias** (instance refresh), para elas lerem o SSM de novo depois de mudar um parâmetro (`APP_KEY`, `DB_HOST`, e-mail) ou para pegar uma imagem nova gravada à mão na `image_tag`:

```powershell
aws autoscaling start-instance-refresh --auto-scaling-group-name barberflow-prod-app --preferences "MinHealthyPercentage=50,InstanceWarmup=300"
aws autoscaling describe-instance-refreshes --auto-scaling-group-name barberflow-prod-app --max-records 1 --query "InstanceRefreshes[0].[Status,PercentageComplete]"
```

O segundo comando mostra o andamento (`Successful` no fim). Mudança no `user_data`, no `docker-compose.prod.yml`, no tipo da instância ou na AMI não precisa disso: o `apply` cria uma versão nova do launch template e começa o instance refresh sozinho. Ao contrário, o instance refresh sem `apply` (e o deploy, que faz o mesmo) não leva essas mudanças: a instância nova sobe com o `user_data` e o compose da versão atual do launch template.

## Senha do RDS (decisão de 13/11)

- A senha do RDS está escrita no `terraform/main.tf`, linha 23 (recurso `aws_db_instance.barbershop_db`, atributo `password`). Foi decisão do Leonam em 13/11: nenhuma senha do projeto muda nesta rodada, e o histórico do Git não é reescrito.
- A `feat/infra-rede` tirou o bloco `provider` do `main.tf` (no lugar ficou um comentário de três linhas, para a senha continuar na linha 23) e deixou o `terraform fmt` alinhar os `=` do RDS. A `feat/infra-dados-seguranca` acrescentou os atributos novos do RDS depois da senha. A `feat/infra-computacao` apagou a EC2 solta, que ficava antes do RDS, e pôs no lugar um comentário das mesmas 10 linhas, que diz para onde ela foi. O valor da senha e a linha 23 não mudaram. Recurso novo vai para outro arquivo, e atributo novo do RDS entra depois da senha.
- O Terraform copia o mesmo valor para o parâmetro SecureString `/barberflow/<ambiente>/DB_PASSWORD`, que a EC2 lê. A cópia vai por referência (`value_wo = aws_db_instance.barbershop_db.password`, no `ssm.tf`), num atributo write-only: o valor não aparece no `plan` e não ganha uma segunda cópia no state. A senha só está escrita no `main.tf`, e o `tests/dados_seguranca.tftest.hcl` confere isso.
- Sem `manage_master_user_password`: com ele, o RDS guardaria a senha no Secrets Manager e trocaria a cada 7 dias, e a aplicação, que lê a senha do SSM ao subir, cairia na primeira troca.
- O risco: quem lê o repositório lê a senha, e o state do RDS também a guarda em texto puro. Por isso o bucket do state é privado, criptografado e só aceita HTTPS. O banco fica nas subnets privadas, e só o security group do app alcança a porta 3306, então a senha sozinha não abre o banco para quem está fora da VPC. O risco fica registrado também no `docs/nuvem/SEGURANCA.md` (branch `feat/observabilidade`).
- Quando o grupo decidir trocar a senha, o caminho que não grava a senha no state é o atributo write-only `password_wo` do RDS (Terraform 1.11 ou mais novo, um dos motivos do `required_version`), com a senha vindo de fora do repositório (variável ou valor `ephemeral`). O `DB_PASSWORD` passa a receber o mesmo valor, e o `password_wo_version` do RDS e o `value_wo_version` do `DB_PASSWORD` sobem juntos.

## Atualizar versões

- **Terraform:** o `required_version` pede 1.11 ou mais novo. Para trocar a imagem, leia as notas da versão nova e troque a tag `1.13.3` e o digest juntos nas três funções deste guia (`tf` no PowerShell e no Git Bash e `tfaws`). O `docker buildx imagetools inspect hashicorp/terraform:<versão>` mostra o digest na linha `Digest`.
- **Provider AWS:** troque o `version` nos dois `versions.tf` (raiz e `bootstrap/`) e refaça os locks para as quatro plataformas que o grupo usa (CI e Docker no Linux, Windows e Mac Intel e Apple Silicon):

```powershell
tf init -backend=false -upgrade
tf providers lock -platform=linux_amd64 -platform=windows_amd64 -platform=darwin_amd64 -platform=darwin_arm64
tf -chdir=bootstrap init -backend=false -upgrade
tf -chdir=bootstrap providers lock -platform=linux_amd64 -platform=windows_amd64 -platform=darwin_amd64 -platform=darwin_arm64
```

O `init -upgrade` escolhe a versão nova e o `providers lock` grava os hashes das quatro plataformas. Depois rode `validate` e `test` e mande os dois `.terraform.lock.hcl` junto no commit.

- **Docker Compose das instâncias:** a versão e o SHA-256 ficam no `compute.tf` (`compose_version` e `compose_sha256`; hoje a v5.7.1, de 11/11/2026). Para trocar, leia as notas da release nova em github.com/docker/compose/releases, pegue no `checksums.txt` dela o SHA-256 da linha `docker-compose-linux-x86_64` e troque os dois juntos. Se só a versão mudar, a instância nova para no passo 2 (o SHA-256 não confere) e nada é instalado. O `apply` cria uma versão nova do launch template e troca as instâncias.
- **AMI:** não tem versão para trocar. Cada `plan` lê a mais nova do Amazon Linux 2023 no parâmetro público do SSM; quando ela muda, o `apply` troca as instâncias.

## Para as próximas branches

- **Nomes:** comece pelo `local.name_prefix` (ex.: `"${local.name_prefix}-alb"`, que vira `barberflow-homolog-alb`).
- **Tags:** o `default_tags` do provider põe `local.tags` em quase tudo. As instâncias e os volumes criados pelo launch template (`tag_specifications`) e as tags do Auto Scaling precisam receber `local.tags` direto.
- **Rede:** `aws_vpc.main.id`, `aws_subnet.public[*].id`, `aws_subnet.private[*].id` e `local.azs`. As saídas `vpc_id`, `public_subnet_ids`, `private_subnet_ids` e `azs` mostram os mesmos valores.
- **Variáveis:** acrescente no fim do `variables.tf` e, se o valor muda por ambiente, nos dois `.tfvars.example`. Nada de senha nos exemplos.
- **Rotas:** nada de `aws_route` avulso. As duas tabelas declaram as rotas dentro delas (`route`), e a documentação do provider não permite misturar os dois jeitos na mesma tabela: um apaga a rota do outro, e a cada `apply` a rota avulsa some e volta. Na tabela privada seria pior, porque banco e cache ganhariam caminho para a internet. Rota nova na pública entra num bloco `route` do `aws_route_table.public`. O `rede.tftest.hcl` lê os `.tf` da pasta e falha se achar um `resource "aws_route"`.
- **Testes:** um arquivo por assunto em `terraform/tests/`, cada um com o próprio `mock_provider`. Políticas de IAM e de bucket em `jsonencode()` nos `locals`: o provider simulado não monta o JSON de um `data "aws_iam_policy_document"`, e o teste não consegue ler a política.
- **Trivy:** alerta novo se corrige. Se ficar, entra no `.trivyignore.yaml` com `paths` (o arquivo do recurso) e `statement` (o motivo), nunca no `.trivyignore`, que valeria para o projeto todo.
- **CI (`feat/ci-cd`):** o `trivy config` do pipeline precisa do `.trivyignore.yaml` (`--ignorefile terraform/.trivyignore.yaml` rodando da raiz, ou o mesmo arquivo na opção de ignore da action).
- **Mocks:** recurso novo cujo ID ou ARN vai para outro recurso (ex.: o ARN de um tópico SNS num alarme) pode precisar de um `mock_resource` com um valor no formato certo nos três arquivos de teste, porque o provider confere o formato e o texto que o mock inventa não passa. Foi o que a `feat/infra-computacao` fez com o ALB, o target group e o launch template.
- **Para a `feat/ci-cd`:**
  - o deploy grava a tag nova em `/barberflow/<ambiente>/image_tag`, e o `ignore_changes` impede o Terraform de desfazer. A role de deploy precisa de `ssm:PutParameter` só nesse parâmetro. As imagens vão para o ECR só com a tag do commit, nunca com `latest`;
  - depois de gravar a tag, o deploy troca as instâncias: `autoscaling:StartInstanceRefresh` e `autoscaling:DescribeInstanceRefreshes` no Auto Scaling `aws_autoscaling_group.app` (saída `asg_name`), com `MinHealthyPercentage=50` e `InstanceWarmup=300`, como o `instance_refresh` do `compute.tf`. Cada instância nova lê a `image_tag` do SSM ao subir;
  - o deploy não leva mudança no `docker-compose.prod.yml` nem no `user_data`: os dois vão no launch template, e só o `apply` cria a versão nova (seção "Launch template e user_data"). Antes de gravar a `image_tag`, o deploy confere se o compose do commit é o embutido e para, pedindo o `apply`, quando não é. O valor embutido está na tag `ComposeFileSha256` do launch template (`aws ec2 describe-launch-templates --launch-template-names barberflow-prod-app --query "LaunchTemplates[0].Tags[?Key=='ComposeFileSha256'].Value | [0]" --output text`), e o do commit sai do `sha256sum docker-compose.prod.yml`. A role de deploy precisa de `ec2:DescribeLaunchTemplates`, só de leitura, que a AWS só aceita com `Resource "*"`. Assim o deploy confere sem ler o state, que guarda a senha do RDS. A saída `compose_file_sha256` traz o mesmo valor para quem roda o Terraform;
  - o teste do `/api/health` usa a saída `app_url`;
  - o job do Terraform no CI precisa do repositório inteiro (o `checkout` padrão já traz), porque o `user_data` lê o `../docker-compose.prod.yml`;
  - o ShellCheck do `user_data` e o teste da montagem do `.env` já estão no repositório (`terraform/tests/user_data/`, seção "Como o user_data foi testado"): no CI, são dois passos com os mesmos comandos, o do teste na forma do Git Bash, com `$(pwd)` no lugar do `$(pwd -W)` e sem o `MSYS_NO_PATHCONV`;
  - se o `cicd.tf` citar a role das instâncias (`aws_iam_role.ec2`, num `iam:PassRole`, por exemplo), a trava do run `iam_role` conta essa linha: atualize o teste junto.
- **Para a `feat/observabilidade`:**
  - os alarmes do RDS usam `aws_db_instance.barbershop_db.identifier`, e os do cache, os nós de `aws_elasticache_replication_group.cache.member_clusters` (as métricas do ElastiCache vêm por nó);
  - os do ALB (5xx, latência e hosts sem saúde) usam `aws_lb.main.arn_suffix` e `aws_lb_target_group.app.arn_suffix`, e os do Auto Scaling, `aws_autoscaling_group.app.name`. As métricas do grupo (`GroupInServiceInstances` e outras) já estão ligadas no `compute.tf`;
  - o orçamento mensal já existe (`budget.tf`, com o e-mail em `budget_alert_email`): o SNS dos alarmes não precisa repetir o aviso de custo;
  - a variável `log_retention_days` já existe: reaproveite, em vez de declarar de novo. Os log groups `/barberflow/nginx` e `/barberflow/app` do `monitoring.tf` da Sprint 4 não voltam: os grupos são os do `logs.tf`, e os streams se chamam `<ID da instância>/<nome do container>`;
  - o `SEGURANCA.md` registra o risco que sobrou no log de erro do Nginx: ele fica no nível `crit` (`docker/nginx/default.conf`), mas uma falha grave ainda grava a requisição inteira, com query string, token de redefinição de senha, assinatura do link de confirmação e e-mail, no grupo `web` do CloudWatch e no Loki (detalhes no `docs/nuvem/DOCKER.md`, seção "Nginx"). Um alarme que conte erros do Nginx deve olhar o status 5xx do log de acesso ou as métricas do ALB, e não o log de erro.

## Problemas comuns

- **`No valid credential sources found`** no `plan` ou no `apply`: falta credencial da AWS. Validar e testar não precisam dela; o resto precisa da conta do grupo (função `tfaws`).
- **`No value for required variable` (ou o Terraform pedindo o `environment`)**: faltou o `-var-file=envs/<ambiente>.tfvars`.
- **`Inconsistent dependency lock file`**: o `versions.tf` mudou e o lock não acompanhou. Siga "Atualizar versões".
- **`Error acquiring the state lock`**: outra pessoa está rodando `plan` ou `apply` no mesmo ambiente. Espere ela terminar. Se a trava ficou presa porque o comando caiu no meio, `terraform force-unlock <ID da trava>` libera, mas só com certeza de que ninguém está rodando.
- **`NoSuchBucket` ou `AccessDenied` no `init`**: o `bucket` ou a `region` do `backend.hcl` não batem com o que o bootstrap criou, ou o perfil da AWS é de outra conta.
- **`Backend initialization required`** no `plan`: a pasta foi iniciada com `-backend=false`, que serve só para validar e testar. Rode o `init` com o `backend.hcl` do passo "Escolher o ambiente".
- **Erro de SSO no `tfaws`** (login vencido, ou `read-only file system` ao renovar o login): a pasta `.aws` entra só para leitura e o container não renova o login. Rode `aws sso login --profile nome-do-perfil-do-grupo` no Windows e repita o comando.
- **`Cannot delete protected DB Instance`** no `destroy`: a prod tem `db_deletion_protection = true`. Veja "Aplicar na AWS".
- **`DBSnapshotAlreadyExists`** no `destroy`: já existe um snapshot final com o mesmo nome, de um `destroy` anterior. Apague ou renomeie o antigo no console do RDS.
- **`RepositoryNotEmptyException`** no `destroy`: o repositório do ECR ainda tem imagens. Apague as imagens antes.
- **`ParameterAlreadyExists`** no `apply`: alguém criou à mão um parâmetro que o Terraform também cria. Apague o parâmetro feito à mão e rode o `apply` de novo.
- **`APP_KEY inválido` no log do container `app`**, e as instâncias não ficam saudáveis no ALB: o `APP_KEY` ainda está com o valor provisório (ou a chave gravada não tem 32 bytes). Veja "Preencher o APP_KEY".
- **`no file exists at "./../docker-compose.prod.yml"`** no `validate`, no `test` ou no `plan` (erro `Invalid function argument` no `compute.tf`): o container do Terraform não enxerga o compose da raiz, porque só a pasta `terraform/` foi montada. Use as funções `tf` e `tfaws` deste guia, que montam o arquivo, e rode da raiz do repositório. Não mexa no `compute.tf`: o arquivo existe, só está fora do container.
- **As instâncias sobem e são trocadas sem parar** (o Auto Scaling mostra instâncias `Unhealthy` no target group): o `user_data` parou ou o app não ficou saudável. Veja o motivo no `/var/log/barberflow-bootstrap.log` (seção "Entrar na instância") ou no console (`aws ec2 get-console-output --instance-id <ID> --latest --output text`). Para parar a troca enquanto investiga, ponha o desejado em 0 (seção "Trocar a capacidade e as instâncias"). Os motivos mais comuns estão abaixo.
- **`nenhum deploy feito ainda`** no log da subida: a `image_tag` ainda tem o valor provisório. Publique as imagens (passo 3 do "Primeiro deploy").
- **`o SHA-256 do Docker Compose baixado não é o esperado`** no log da subida: o binário baixado não é o da versão fixada no `compute.tf`. Confira a versão e o hash juntos (seção "Atualizar versões"); nunca troque só o hash sem conferir de onde veio o arquivo.
- **`parâmetro com nome que não serve de variável de ambiente`**, **`parâmetro com nome reservado ao user_data`** ou **`valor que o .env não representa`** no log da subida: algum parâmetro de `/barberflow/<ambiente>/` foi criado à mão com nome fora do padrão (só letras, números e `_`, sem pasta dentro do caminho), com um nome que o `user_data` escreve (`APP_IMAGE`, `WEB_IMAGE`, `AWSLOGS_REGION`, `AWSLOGS_WEB_GROUP` ou `INSTANCE_ID`) ou com barra invertida antes de aspas simples ou no fim do valor. O log cita o nome do parâmetro; corrija e faça o instance refresh.
- **`Invalid value for variable` citando o `app_url` ou o `certificate_arn`** no `plan`: domínio em `https://` precisa do certificado, e certificado precisa do domínio em `https://`, na mesma região (seção "HTTPS com domínio próprio").
- **`cannot be deleted because deletion protection is enabled`** no `destroy` do ALB: a prod tem `alb_deletion_protection = true`. Veja "Aplicar na AWS".
