# Terraform do BarberFlow

Este guia explica a infraestrutura como código do BarberFlow na AWS: o que cada parte cria, como validar e testar no seu computador sem conta da AWS, como criar o bucket do state remoto e como escolher o ambiente. O backup e o restore do banco ficam no `docs/nuvem/BACKUP.md`.

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
| `terraform/variables.tf` | Variáveis da base, da rede e dos dados |
| `terraform/locals.tf` | Prefixo dos nomes (ex.: `barberflow-homolog`) e as tags |
| `terraform/network.tf` | VPC, subnets, internet gateway, tabelas de rotas e o security group padrão sem regras |
| `terraform/sg.tf` | Security groups do ALB, do app, do banco e do cache, com as regras de entrada e de saída |
| `terraform/iam.tf` | Role e instance profile das instâncias do app, com a política mínima |
| `terraform/ecr.tf` | Repositórios do ECR das imagens `app` e `web`, com a limpeza automática |
| `terraform/logs.tf` | Log groups do CloudWatch: containers `app` e `web` e os logs do RDS |
| `terraform/main.tf` | Recursos da Sprint 1: a EC2 solta e o RDS, com o grupo de subnets e o parameter group (veja "Senha do RDS") |
| `terraform/cache.tf` | ElastiCache (Valkey) para cache e sessões |
| `terraform/ssm.tf` | Parâmetros do SSM que viram o `.env` da EC2 |
| `terraform/outputs.tf` | IDs da VPC e das subnets, zonas, security groups, instance profile, ECR, log groups, banco, cache e caminho do SSM |
| `terraform/envs/*.tfvars.example` | Valores de cada ambiente, homolog e prod |
| `terraform/tests/rede.tftest.hcl` | Testes da rede com o provider simulado |
| `terraform/tests/dados_seguranca.tftest.hcl` | Testes dos dados e da segurança com o provider simulado |
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
- O parâmetro `image_tag` nasce com o valor provisório `PREENCHER_PELO_DEPLOY`, que não aponta para imagem nenhuma. Até o primeiro deploy da `feat/ci-cd`, que grava a tag do commit, o `user_data` da `feat/infra-computacao` precisa parar com uma mensagem clara (veja "Para as próximas branches"). A tag `latest` nunca é usada: com tags imutáveis, uma `latest` publicada à mão ficaria presa para sempre a uma imagem que não passou pelo CI.

### Logs (`logs.tf`)

| Log group | O que guarda |
|---|---|
| `/barberflow/<ambiente>/app` | PHP-FPM e agendador (containers `app` e `scheduler`) |
| `/barberflow/<ambiente>/web` | Nginx (container `web`) |
| `/aws/rds/instance/barberflow-<ambiente>-db/error` | Log de erro do MySQL |
| `/aws/rds/instance/barberflow-<ambiente>-db/slowquery` | Consultas de mais de 1 segundo |

- Todos guardam `log_retention_days` dias (padrão 30).
- Os dois do RDS nascem antes do banco. Se não existissem, o RDS criaria os dele, sem prazo de validade.
- **Decisão para a `feat/infra-computacao`: cada serviço escreve no seu grupo.** O `daemon.json` da EC2 manda os logs de todos os containers para o grupo `app`, que fica como padrão (atende o `app` e o `scheduler`). O `docker-compose.prod.yml` ganha, na N4, um `logging:` só no serviço `web`, apontando para o grupo `web`, com o nome vindo do `.env` que o `user_data` monta. O `docker-compose.homolog.yml` volta o `web` ao driver padrão com `!override`, para a homolog continuar subindo no Docker Desktop sem credencial da AWS.

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

Os parâmetros ficam em `/barberflow/<ambiente>/` e viram o `.env` da EC2. O `user_data` da `feat/infra-computacao` lê o caminho inteiro (`aws ssm get-parameters-by-path --path /barberflow/<ambiente> --with-decryption`) e grava uma linha `NOME=valor` por parâmetro, ao lado do `docker-compose.prod.yml`. O bloco de produção desse compose (`APP_ENV`, `APP_DEBUG`, `LOG_CHANNEL`, `LOG_LEVEL`, `CACHE_STORE` e `SESSION_DRIVER`) vale mais que o `.env`. Os mesmos valores estão também no SSM, para o `.env` da EC2 ficar completo sozinho, inclusive para quem rodar a imagem fora do compose.

| Parâmetro | Tipo | Valor | Quem cuida do valor |
|---|---|---|---|
| `DB_PASSWORD` | SecureString | A senha do RDS no `main.tf`, por referência | Terraform (`value_wo`) |
| `APP_KEY` | SecureString | Provisório (`PREENCHER_COM_AWS_SSM_PUT_PARAMETER`), gravado pelo `value_wo` | Operador (veja "Preencher o APP_KEY") |
| `image_tag` | String | Provisório (`PREENCHER_PELO_DEPLOY`) | Deploy da `feat/ci-cd` |
| `APP_NAME` | String | `BarberFlow` | Terraform |
| `APP_ENV`, `APP_DEBUG`, `LOG_CHANNEL` e `LOG_LEVEL` | String | `production`, `false`, `stderr` e `info`, os mesmos do `docker-compose.prod.yml` | Terraform |
| `APP_URL` | String | Variável `app_url`; só existe com domínio | Terraform |
| `DB_CONNECTION` | String | `mysql` | Terraform |
| `DB_HOST` e `DB_PORT` | String | Endereço e porta do RDS | Terraform |
| `DB_DATABASE` e `DB_USERNAME` | String | Banco e usuário do `main.tf` | Terraform |
| `MYSQL_ATTR_SSL_CA` | String | `/etc/ssl/certs/rds-global-bundle.pem`, o bundle que a imagem traz | Terraform |
| `REDIS_HOST` e `REDIS_PORT` | String | `tls://<endereço do primário>` e `6379` | Terraform |
| `CACHE_STORE` e `SESSION_DRIVER` | String | `redis` | Terraform |
| `SESSION_SECURE_COOKIE` | String | `false`, enquanto o ALB não tiver certificado | Terraform |
| `APP_MAINTENANCE_DRIVER` e `APP_MAINTENANCE_STORE` | String | `cache` e `redis` | Terraform |
| `RUN_MIGRATIONS` | String | `true` | Terraform |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` e `MAIL_PORT` | String | Variáveis `mail_mailer`, `mail_from_address` e `mail_port` | Terraform |
| `MAIL_HOST` | String | Variável `mail_host`; só existe com o provedor escolhido | Terraform |
| `MAIL_USERNAME` e `MAIL_PASSWORD` | SecureString | Usuário e senha do provedor de e-mail | Operador, à mão (veja abaixo) |

- "Terraform" quer dizer que o próximo `apply` desfaz uma mudança feita à mão.
- `APP_KEY` e `image_tag` o Terraform cria com o valor provisório e não mexe mais. O `APP_KEY` vai pelo atributo write-only `value_wo`, mandado uma vez só (veja "Preencher o APP_KEY"), e a `image_tag` tem `ignore_changes`.
- Nenhum parâmetro usa o atributo `value`, que guarda no state o valor lido da AWS, inclusive o de um SecureString. O teste confere.
- `DB_CONNECTION` e `APP_NAME` não estavam na lista do plano. Sem o primeiro, o Laravel cai no sqlite (o padrão do `config/database.php`); sem o segundo, assina os e-mails como "Laravel".
- `APP_URL` e `MAIL_HOST`: o SSM não aceita parâmetro vazio. Com `app_url = ""` (sem domínio), o `APP_URL` não é criado nesta branch, e a `feat/infra-computacao` passa a criar com o DNS do ALB. Com `mail_host = ""` (provedor ainda não escolhido), o `MAIL_HOST` não é criado e o e-mail não sai.
- `SESSION_SECURE_COOKIE` fica `false` enquanto o ALB não tem certificado: com `true`, o navegador não manda o cookie de sessão em HTTP, e o login não segura. A `feat/infra-computacao` liga (`true`) quando houver `certificate_arn`.
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
- Para trocar a chave depois, repita os passos e faça o instance refresh (`feat/infra-computacao`): as instâncias leem o SSM só quando sobem.

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

## Antes de começar

- Docker Desktop. A imagem `hashicorp/terraform:1.13.3` roda o Terraform sem instalar nada. Quem já tem o Terraform 1.11 ou mais novo instalado pode usar os mesmos comandos sem o `docker run`.
- Validar e testar não precisam de conta da AWS nem de credencial.
- Aplicar precisa da conta do grupo (veja "Aplicar na AWS").

## Validar e testar sem conta da AWS

No PowerShell, na raiz do repositório, crie a função `tf`. Ela vale só nessa janela do terminal:

```powershell
function tf { docker run --rm -v "${PWD}\terraform:/tf" -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /tf hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 @args }
```

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

O `test` termina com `Success! 27 passed, 0 failed.` na raiz (8 da rede e 19 dos dados e da segurança) e `Success! 1 passed, 0 failed.` no bootstrap.

No Git Bash, a função fica assim (o `MSYS_NO_PATHCONV=1` impede o Git Bash de trocar o `/tf` por um caminho do Windows):

```bash
tf() { MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/terraform:/tf" -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /tf hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 "$@"; }
```

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
- SSM: a lista exata de parâmetros, `DB_PASSWORD` e `APP_KEY` como SecureString e o resto como String, os valores (bloco de produção, banco, cache com `tls://`, sessão, manutenção e e-mail), o `APP_URL` só com domínio, o `MAIL_HOST` só com provedor, a saída SMTP do app na mesma porta do `MAIL_PORT`, os valores provisórios (`image_tag` nunca `latest`), o `DB_PASSWORD` e o `APP_KEY` pelo `value_wo` e nenhum parâmetro do `ssm.tf` com o atributo `value`;
- as saídas, a prod (nomes e permissões com `prod`) e os padrões das variáveis;
- recusa failover com 1 nó, cache sem nó, tipo de nó inválido, retenção de log que o CloudWatch não aceita, guardar só 1 imagem, `app_url` com barra ou caminho no fim, `mail_mailer = "log"`, remetente sem `@`, `mail_host` com protocolo e `mail_port` 25.

Cada regra desse arquivo foi conferida quebrando a configuração de propósito numa cópia da pasta (banco e app abertos para a internet por regra escrita dentro do security group, saída do app com todos os protocolos, RDS sem criptografia, ação com curinga, `AdministratorAccess` anexada à role, `APP_KEY` como String ou pelo `value`, `image_tag` com `latest` e outros): em todos os casos o teste falhou.

`terraform/bootstrap/tests/state.tftest.hcl`: nome do bucket com o ID da conta, versionamento, criptografia, as quatro travas de acesso público, a política que nega acesso sem HTTPS (no bucket e nos objetos) e o bucket sem `force_destroy`.

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
| AWS-0028 e AWS-0131 | `main.tf`, EC2 solta | Sem IMDSv2 e com disco sem criptografia | A `feat/infra-computacao` troca a EC2 pelo Auto Scaling e apaga os itens |
| AWS-0176 | `main.tf`, RDS | Login por IAM desligado: a aplicação entra com usuário e senha (decisão de 13/11), e o Laravel não gera nem renova o token do IAM, que vence em 15 minutos | Fica |
| AWS-0133 | `main.tf`, RDS | Performance Insights (hoje CloudWatch Database Insights) não roda no `db.t3.micro`; a AWS só aceita a partir do `db.t3.medium`, e o modo Advanced é pago | Fica enquanto a classe for `db.t3.micro` |
| AWS-0104 | `sg.tf`, saída do app | Saída para a internet só na 443 e na porta do SMTP, porque não há NAT Gateway nem VPC endpoints | Fica enquanto não houver um dos dois |
| AWS-0033 | `ecr.tf`, repositórios | AES256 com a chave da AWS, e não uma chave do KMS do grupo (US$ 1 por mês) | Fica |
| AWS-0017 | `logs.tf`, log groups | Criptografia padrão do CloudWatch, e não uma chave do KMS do grupo | Fica |
| AWS-0178 | `network.tf`, VPC | Sem Flow Logs, cortados no plano como excesso | Fica |
| AWS-0132 | `bootstrap/main.tf`, bucket do state | Criptografia SSE-S3 em vez de uma chave do KMS (US$ 1 por mês e mais uma linha no `backend.hcl`) | Fica |
| AWS-0089 | `bootstrap/main.tf`, bucket do state | Sem log de acesso, que pediria um segundo bucket | Fica |

A `feat/infra-dados-seguranca` corrigiu e apagou os itens AWS-0077 (backup curto), AWS-0080 (RDS sem criptografia) e AWS-0177 (sem proteção contra exclusão).

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
function tfaws { docker run --rm -it -v "${PWD}\terraform:/tf" -v "$env:USERPROFILE\.aws:/root/.aws:ro" -e AWS_PROFILE -v barberflow-tf-plugins:/plugins -e TF_PLUGIN_CACHE_DIR=/plugins -w /tf hashicorp/terraform:1.13.3@sha256:dfb1889a8ee74ada3ddacc48f89b8a0d69f3e114de3d8a2ce15f6a8d3dbfdbe2 @args }
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

Aplicar só com a conta do grupo, combinado com todos, porque cria recursos que cobram por hora. Hoje o conjunto ainda não está pronto para isso: o `main.tf` ainda tem a EC2 solta da Sprint 1, que a `feat/infra-computacao` troca pelo Auto Scaling, e é ela que cria o ALB e o `APP_URL` sem domínio. O primeiro `apply` de verdade fica para depois dela. Antes de abrir o ambiente, preencha o `APP_KEY` (seção "Preencher o APP_KEY").

Quando chegar a hora, aplique o plano salvo, que é exatamente o que foi revisado:

```powershell
tfaws apply homolog.tfplan
```

O plano salvo (`*.tfplan`) guarda valores sensíveis e o Git ignora. Depois da apresentação, `tfaws destroy "-var-file=envs/homolog.tfvars"` apaga o ambiente (com o `init` feito na `key` dele). O bucket do state sai por último, pelo bootstrap, e só depois de esvaziado: com versionamento, isso inclui as versões antigas, que o console do S3 apaga com a opção "Esvaziar".

Três recursos travam o `destroy` de propósito:

- **Banco da prod:** com `db_deletion_protection = true`, nem o `destroy` apaga. Mude para `false` no `envs/prod.tfvars`, rode `plan` e `apply`, e só depois o `destroy`.
- **Snapshot final:** o `destroy` tira o snapshot `barberflow-<ambiente>-db-final` antes de apagar o banco. Se um snapshot com esse nome já existir (de um `destroy` anterior), o comando para; apague ou renomeie o antigo no console do RDS. O snapshot fica guardado, e cobrando, até alguém apagar.
- **ECR com imagens:** o repositório só sai vazio. Apague as imagens no console do ECR antes. O Terraform não usa `force_delete`, para ninguém perder as imagens sem querer.

## Senha do RDS (decisão de 13/11)

- A senha do RDS está escrita no `terraform/main.tf`, linha 23 (recurso `aws_db_instance.barbershop_db`, atributo `password`). Foi decisão do Leonam em 13/11: nenhuma senha do projeto muda nesta rodada, e o histórico do Git não é reescrito.
- A `feat/infra-rede` tirou o bloco `provider` do `main.tf` (no lugar ficou um comentário de três linhas, para a senha continuar na linha 23) e deixou o `terraform fmt` alinhar os `=` do RDS. A `feat/infra-dados-seguranca` acrescentou os atributos novos do RDS depois da senha. O valor da senha e a linha 23 não mudaram.
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

## Para as próximas branches

- **Nomes:** comece pelo `local.name_prefix` (ex.: `"${local.name_prefix}-alb"`, que vira `barberflow-homolog-alb`).
- **Tags:** o `default_tags` do provider põe `local.tags` em quase tudo. As instâncias e os volumes criados pelo launch template (`tag_specifications`) e as tags do Auto Scaling precisam receber `local.tags` direto.
- **Rede:** `aws_vpc.main.id`, `aws_subnet.public[*].id`, `aws_subnet.private[*].id` e `local.azs`. As saídas `vpc_id`, `public_subnet_ids`, `private_subnet_ids` e `azs` mostram os mesmos valores.
- **Variáveis:** acrescente no fim do `variables.tf` e, se o valor muda por ambiente, nos dois `.tfvars.example`. Nada de senha nos exemplos.
- **Rotas:** nada de `aws_route` avulso. As duas tabelas declaram as rotas dentro delas (`route`), e a documentação do provider não permite misturar os dois jeitos na mesma tabela: um apaga a rota do outro, e a cada `apply` a rota avulsa some e volta. Na tabela privada seria pior, porque banco e cache ganhariam caminho para a internet. Rota nova na pública entra num bloco `route` do `aws_route_table.public`. O `rede.tftest.hcl` lê os `.tf` da pasta e falha se achar um `resource "aws_route"`.
- **Testes:** um arquivo por assunto em `terraform/tests/`, cada um com o próprio `mock_provider`. Políticas de IAM e de bucket em `jsonencode()` nos `locals`: o provider simulado não monta o JSON de um `data "aws_iam_policy_document"`, e o teste não consegue ler a política.
- **Trivy:** alerta novo se corrige. Se ficar, entra no `.trivyignore.yaml` com `paths` (o arquivo do recurso) e `statement` (o motivo), nunca no `.trivyignore`, que valeria para o projeto todo.
- **CI (`feat/ci-cd`):** o `trivy config` do pipeline precisa do `.trivyignore.yaml` (`--ignorefile terraform/.trivyignore.yaml` rodando da raiz, ou o mesmo arquivo na opção de ignore da action).
- **Dados e segurança, para a `feat/infra-computacao`:**
  - security groups: `aws_security_group.alb.id` no ALB e `aws_security_group.app.id` no launch template (saídas `alb_security_group_id` e `app_security_group_id`);
  - instance profile: `aws_iam_instance_profile.ec2.name` no launch template;
  - imagens: `aws_ecr_repository.image["app"].repository_url` e `aws_ecr_repository.image["web"].repository_url`, com a tag do parâmetro `image_tag`. Enquanto a `image_tag` tiver o valor provisório (`local.ssm_operator_parameters["image_tag"].initial_value`, hoje `PREENCHER_PELO_DEPLOY`), o `user_data` para com uma mensagem clara ("nenhum deploy feito ainda"), em vez de tentar baixar uma imagem que não existe. O `APP_KEY` provisório já é recusado pelo entrypoint de produção;
  - logs: `aws_cloudwatch_log_group.container["app"].name` e `["web"].name`, com a decisão da seção "Logs";
  - parâmetros: o `user_data` lê `local.ssm_path` inteiro, com `--with-decryption` (a role já tem a permissão), e grava o `.env`;
  - saída do app: só 443, a porta do SMTP, 3306 e 6379 (seção "Security groups"). O `user_data` baixa tudo por HTTPS; se precisar de outra porta, acrescente a regra no `sg.tf` e no teste;
  - `APP_URL`: no `ssm.tf`, tire o `APP_URL` do mapa dos parâmetros que só existem com valor (`{ for name, value in { APP_URL = var.app_url, MAIL_HOST = var.mail_host } : ... }`) e ponha no mapa principal, com um valor que use o DNS do ALB quando o `app_url` estiver vazio;
  - `SESSION_SECURE_COOKIE`: passe a `"true"` quando houver `certificate_arn`.
- **Para a `feat/ci-cd`:** o deploy grava a tag nova em `/barberflow/<ambiente>/image_tag`, e o `ignore_changes` impede o Terraform de desfazer. A role de deploy precisa de `ssm:PutParameter` só nesse parâmetro. As imagens vão para o ECR só com a tag do commit, nunca com `latest`. Se o `cicd.tf` citar a role das instâncias (`aws_iam_role.ec2`, num `iam:PassRole`, por exemplo), a trava do run `iam_role` conta essa linha: atualize o teste junto.
- **Para a `feat/observabilidade`:** os alarmes do RDS usam `aws_db_instance.barbershop_db.identifier`, e os do cache, os nós de `aws_elasticache_replication_group.cache.member_clusters` (as métricas do ElastiCache vêm por nó). A variável `log_retention_days` já existe: reaproveite, em vez de declarar de novo. Os log groups `/barberflow/nginx` e `/barberflow/app` do `monitoring.tf` da Sprint 4 não voltam: os grupos são os do `logs.tf`.

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
