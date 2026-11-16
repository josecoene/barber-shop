# Terraform do BarberFlow

Este guia explica a infraestrutura como código do BarberFlow na AWS: como validar e testar no seu computador sem conta da AWS, como criar o bucket do state remoto e como escolher o ambiente.

Nada daqui foi aplicado na AWS. Aplicar cria recursos que cobram por hora, então só acontece com a conta do grupo (veja "Aplicar na AWS").

O Terraform chega em cinco branches:

| Branch | Dono | O que traz |
|---|---|---|
| `feat/infra-rede` | Manoel | Versões, state remoto, variáveis, VPC e subnets (a parte descrita neste guia) |
| `feat/infra-dados-seguranca` | Eduardo | Security groups, IAM, ECR, logs, RDS, cache e parâmetros |
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
| `terraform/variables.tf` | Variáveis da base e da rede |
| `terraform/locals.tf` | Prefixo dos nomes (ex.: `barberflow-homolog`) e as tags |
| `terraform/network.tf` | VPC, subnets, internet gateway, tabelas de rotas e o security group padrão sem regras |
| `terraform/outputs.tf` | IDs da VPC e das subnets e as zonas usadas |
| `terraform/main.tf` | Recursos da Sprint 1: a EC2 solta e o RDS (veja "Senha do RDS") |
| `terraform/envs/*.tfvars.example` | Valores de cada ambiente, homolog e prod |
| `terraform/tests/rede.tftest.hcl` | Testes da rede com o provider simulado |
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

O `test` termina com `Success! 8 passed, 0 failed.` na raiz e `Success! 1 passed, 0 failed.` no bootstrap.

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

Os alertas aceitos ficam no `terraform/.trivyignore.yaml`. Cada item tem o ID do alerta, o arquivo onde ele vale (`paths`) e o motivo (`statement`). Como cada aceite fica preso ao arquivo, o alerta continua valendo no resto do projeto: um RDS novo sem criptografia num arquivo novo é acusado, mesmo com o AWS-0080 aceito no `main.tf`. Um `.trivyignore` comum, só com os IDs, aceitaria o alerta em qualquer arquivo; por isso o da pasta fica vazio.

| Alerta | Onde (`paths`) | Por que fica | Até quando |
|---|---|---|---|
| AWS-0028 e AWS-0131 | `main.tf`, EC2 solta | Sem IMDSv2 e com disco sem criptografia | A `feat/infra-computacao` troca a EC2 pelo Auto Scaling e apaga os itens |
| AWS-0077, AWS-0080 e AWS-0177 | `main.tf`, RDS | Backup curto, sem criptografia e sem proteção contra exclusão; o recurso fica como está nesta branch | A `feat/infra-dados-seguranca` corrige e apaga os itens |
| AWS-0176 e AWS-0133 | `main.tf`, RDS | Login por IAM e Performance Insights desligados | A `feat/infra-dados-seguranca` decide e escreve o motivo |
| AWS-0178 | `network.tf`, VPC | Sem Flow Logs, cortados no plano como excesso | Fica |
| AWS-0132 | `bootstrap/main.tf`, bucket do state | Criptografia SSE-S3 em vez de uma chave do KMS (US$ 1 por mês e mais uma linha no `backend.hcl`) | Fica |
| AWS-0089 | `bootstrap/main.tf`, bucket do state | Sem log de acesso, que pediria um segundo bucket | Fica |

O Trivy não acusa a senha escrita no `main.tf`: o `trivy config` não tem regra para senha em atributo, e o scanner de segredos (`trivy fs --scanners secret`) procura formatos conhecidos, como chave de acesso da AWS. O risco está descrito em "Senha do RDS".

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

Aplicar só com a conta do grupo, combinado com todos, porque cria recursos que cobram por hora. Hoje o conjunto ainda não está pronto para isso: o `main.tf` tem a EC2 solta e o RDS fora da VPC nova, que mudam nas branches `feat/infra-dados-seguranca` e `feat/infra-computacao`. O primeiro `apply` de verdade fica para depois delas.

Quando chegar a hora, aplique o plano salvo, que é exatamente o que foi revisado:

```powershell
tfaws apply homolog.tfplan
```

O plano salvo (`*.tfplan`) guarda valores sensíveis e o Git ignora. Depois da apresentação, `tfaws destroy "-var-file=envs/homolog.tfvars"` apaga o ambiente (com o `init` feito na `key` dele). O bucket do state sai por último, pelo bootstrap, e só depois de esvaziado: com versionamento, isso inclui as versões antigas, que o console do S3 apaga com a opção "Esvaziar".

## Senha do RDS (decisão de 13/11)

- A senha do RDS está escrita no `terraform/main.tf`, linha 23 (recurso `aws_db_instance.barbershop_db`, atributo `password`). Foi decisão do Leonam em 13/11: nenhuma senha do projeto muda nesta rodada, e o histórico do Git não é reescrito.
- Esta branch só tirou o bloco `provider` do `main.tf` (no lugar ficou um comentário de três linhas, para a senha continuar na linha 23) e deixou o `terraform fmt` alinhar os `=` do RDS. O valor da senha e o recurso não mudaram.
- O risco: quem lê o repositório lê a senha, e o state também a guarda em texto puro. Por isso o bucket do state é privado, criptografado e só aceita HTTPS. O risco fica registrado também no `docs/nuvem/SEGURANCA.md` (branch `feat/observabilidade`).
- Na `feat/infra-dados-seguranca`, o RDS vai para as subnets privadas e o mesmo valor segue para o parâmetro SecureString `/barberflow/<ambiente>/DB_PASSWORD`, que a EC2 lê.
- Quando o grupo decidir trocar a senha, o caminho que não grava a senha no state é o atributo write-only `password_wo` do RDS (Terraform 1.11 ou mais novo, um dos motivos do `required_version`).

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

## Problemas comuns

- **`No valid credential sources found`** no `plan` ou no `apply`: falta credencial da AWS. Validar e testar não precisam dela; o resto precisa da conta do grupo (função `tfaws`).
- **`No value for required variable` (ou o Terraform pedindo o `environment`)**: faltou o `-var-file=envs/<ambiente>.tfvars`.
- **`Inconsistent dependency lock file`**: o `versions.tf` mudou e o lock não acompanhou. Siga "Atualizar versões".
- **`Error acquiring the state lock`**: outra pessoa está rodando `plan` ou `apply` no mesmo ambiente. Espere ela terminar. Se a trava ficou presa porque o comando caiu no meio, `terraform force-unlock <ID da trava>` libera, mas só com certeza de que ninguém está rodando.
- **`NoSuchBucket` ou `AccessDenied` no `init`**: o `bucket` ou a `region` do `backend.hcl` não batem com o que o bootstrap criou, ou o perfil da AWS é de outra conta.
- **`Backend initialization required`** no `plan`: a pasta foi iniciada com `-backend=false`, que serve só para validar e testar. Rode o `init` com o `backend.hcl` do passo "Escolher o ambiente".
- **Erro de SSO no `tfaws`** (login vencido, ou `read-only file system` ao renovar o login): a pasta `.aws` entra só para leitura e o container não renova o login. Rode `aws sso login --profile nome-do-perfil-do-grupo` no Windows e repita o comando.
