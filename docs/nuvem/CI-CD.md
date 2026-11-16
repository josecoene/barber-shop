# CI/CD do BarberFlow

Este guia explica o pipeline do BarberFlow no GitHub Actions: o CI, que confere cada pull request e cada push na `main`, e o deploy na AWS, que publica as imagens no ECR e troca as instâncias do Auto Scaling. Também traz a role de deploy (`terraform/cicd.tf`), a configuração do GitHub que o José liga (proteção da `main`, environment `production` e Dependabot), o rollback, as migrations e a ordem com o `terraform apply`.

**Situação: validado só no código.** Nada foi para a AWS. As 13 branches estão no GitHub desde 16/11/2026 (o push foi feito com o OK do Leonam, sem mexer na `main` de lá), mas nenhum workflow rodou: o CI só roda no pull request e no push na `main` (`.github/workflows/ci.yml:12-16`), e o deploy, com tag `v*` ou à mão (`.github/workflows/deploy.yml:16-20`). Os dois workflows passam no actionlint, o `cicd.tf` passa no `terraform validate`, no `terraform test` e no `trivy config`, e os passos de cada job do CI rodaram no computador, em containers (seção "Situação conferida em 14/11/2026"). O CI verde no GitHub só se prova no primeiro pull request que tiver o `ci.yml` (o da `feat/ci-cd`, o da `feat/observabilidade` ou o da `feat/entrega-final`, ou qualquer um depois que o `ci.yml` chegar à `main`), e o OIDC, o deploy e o rollback, depois do primeiro `apply`.

| Parte | Dono | Arquivos |
|---|---|---|
| CI | José | `.github/workflows/ci.yml` e `.github/dependabot.yml` |
| CD | Manoel | `.github/workflows/deploy.yml`, `terraform/cicd.tf` e `terraform/tests/cicd.tftest.hcl` |

## Arquivos

| Arquivo | Para que serve |
|---|---|
| `.github/workflows/ci.yml` | CI: PHP, MySQL, front, imagens Docker, Terraform e actionlint |
| `.github/workflows/deploy.yml` | Deploy na AWS (tag `v*`) e rollback (à mão, com o `image_tag`) |
| `.github/dependabot.yml` | Pull requests semanais com as versões novas das dependências |
| `terraform/cicd.tf` | Provedor OIDC do GitHub e a role de deploy, com a política mínima |
| `terraform/tests/cicd.tftest.hcl` | Testes da role de deploy com o provider simulado |
| `terraform/variables.tf` e `terraform/outputs.tf` | Variáveis do CI/CD (no fim do arquivo) e as saídas que viram as variáveis do environment `production` |

## O CI (`ci.yml`)

Roda em todo pull request, para qualquer branch, e em cada push na `main`.

- Um push novo no mesmo pull request (ou na mesma branch) cancela a rodada que ficou velha (`concurrency`).
- O token do GitHub só lê o repositório (`permissions: contents: read`), e o checkout não guarda a credencial no disco (`persist-credentials: false`).
- Cada job tem um limite de tempo (`timeout-minutes`).
- Versões fixas: cada action pelo SHA do commit da release, com a versão no comentário; cada imagem Docker pela tag exata e pelo digest; o Composer pela versão e pelo SHA-256 do `composer.phar`, que o setup-php confere antes de instalar.

| Job (nome do check) | O que faz | Limite |
|---|---|---|
| `PHP` | PHP 8.4 com pcov e sem intl, `composer install`, `.env` de teste, Pint, `composer test` com cobertura e `composer audit` | 15 min |
| `MySQL` | A suíte inteira num MySQL 8.4.13 descartável, com o `phpunit.mysql.xml`. Sem o arquivo, avisa e termina verde | 20 min |
| `Front` | Node 24, `npm ci`, `npm run build` e `npm audit --audit-level=high` | 10 min |
| `Docker` | Build dos alvos `app` e `web` do `docker/php/Dockerfile` e Trivy nas duas imagens | 30 min |
| `Terraform` | `fmt -check`, `trivy config`, `init`, `validate` e `test` (raiz e bootstrap), ShellCheck do `user_data` e montagem do `.env` no Amazon Linux 2023 | 20 min |
| `actionlint` | Confere os dois workflows, com o ShellCheck nos passos `run` | 5 min |

### PHP

- `shivammathur/setup-php` com `pdo_sqlite`, `sqlite3`, `pdo_mysql` e `pcov`, e com a `intl` desligada (`:intl`): a imagem de produção não tem a intl. Se alguém usar o `Number::` do Laravel (formatar moeda, por exemplo), que precisa dela, o teste falha aqui, e não na AWS (`docs/nuvem/DOCKER.md`, "Extensões do PHP").
- Composer 2.11.2, o mesmo do `docker/php/Dockerfile`, com o cache dos pacotes preso ao conteúdo do `composer.lock`.
- O `.env` sai do `.env.example`, com uma `APP_KEY` nova a cada rodada (`php artisan key:generate`): a suíte precisa da chave para a sessão e os cookies. O banco dos testes continua o SQLite em memória, que o `phpunit.xml` força.
- Pint, testes e `composer audit` rodam mesmo que um deles falhe. O pull request mostra os três resultados de uma vez, e o job fica vermelho se qualquer um falhar.
- `composer test -- --coverage --coverage-clover=build/coverage/clover.xml`: o `composer test` limpa o cache de configuração e roda o `php artisan test`, e o `@no_additional_args` do `composer.json` manda os argumentos só para o `test`. A cobertura por arquivo e o total aparecem no log. O `clover.xml` fica 14 dias como o artefato `cobertura-clover`, na página da rodada.
- O job não roda o `npm run build`, de propósito: um teste que abre tela sem `withoutVite()` falha aqui com `Vite manifest not found`, como pede o `AGENTS.md`.
- `composer audit` falha com qualquer alerta de segurança, de qualquer severidade. No Composer, quase todo alerta já sai com a versão corrigida, e a correção é um `composer update <pacote>` num pull request.

### MySQL

- Serviço `mysql:8.4.13` (com digest), com o banco `barberflow_testing`, o usuário `ci_tests` e a senha `ci_tests_only`. Os dois valem só dentro do job, num banco que nasce e morre com ele: não são senha do projeto. O root ganha uma senha aleatória, que nada usa.
- O GitHub espera o healthcheck (`mysqladmin ping` pela porta TCP) antes do primeiro passo. Durante a inicialização o MySQL não escuta na porta TCP, então o teste só começa com o servidor de verdade no ar.
- Os `DB_*` ficam no ambiente do job. O `phpunit.mysql.xml` não força `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` nem `DB_PASSWORD` (só o `DB_URL`, sempre vazio), então o ambiente vale mais que os padrões do arquivo e que o `.env`.
- O `phpunit.mysql.xml` chega com a `feat/agendamentos`. Num commit sem ele, um passo deixa o aviso "Testes no MySQL pulados" e o job termina verde.

### Front

- Node 24, a versão mais nova da linha 24 no runner (a imagem `assets` do Dockerfile fixa a 24.22.3), com o cache do npm pelo `package-lock.json`.
- `npm audit --audit-level=high`: falha com alerta alto ou crítico. Os moderados e baixos aparecem no log sem travar o pull request. O front só gera CSS e JS estáticos, e quase todo alerta do npm cai nas ferramentas de build, que não vão para a produção.

### Docker

- Buildx, com o cache de cada imagem no GitHub Actions (`type=gha`, um escopo por imagem).
- O BuildKit, que o Buildx sobe num container e que roda os `RUN` do Dockerfile, vai fixado pela versão e pelo digest (`BUILDKIT_IMAGE` no bloco `env`). Sem isso, o Buildx baixaria a `moby/buildkit:buildx-stable-1`, uma tag que muda de conteúdo a cada versão nova.
- As duas imagens viram arquivos `.tar` no runner, e o Trivy 0.76.2 lê esses arquivos (`--input`).
- `--ignore-unfixed --severity CRITICAL --exit-code 1`: falha só com vulnerabilidade crítica que já tem correção publicada. A correção é atualizar a imagem base ou o pacote. As que ainda não têm correção não travam.
- A imagem do Trivy vai fixada por digest. Nunca a 0.69.4, nem as imagens 0.69.5 e 0.69.6 do Docker Hub, publicadas por um invasor em março de 2026 (`docs/nuvem/TERRAFORM.md`, "Trivy").
- O deploy não reaproveita estas imagens: ele faz o build de novo, limpo, a partir do commit que vai para a AWS, e passa o mesmo Trivy antes do push.

### Terraform

- `hashicorp/setup-terraform` na versão 1.13.3, a mesma da imagem do `docs/nuvem/TERRAFORM.md`, sem o wrapper.
- O provider AWS (perto de 770 MB) fica no cache do GitHub, pela versão dos dois `.terraform.lock.hcl`.
- Na ordem: `fmt -check -recursive`; `trivy config` com o `.trivyignore.yaml`, antes do `init`, para ler só os `.tf`; `init -backend=false`, `validate` e `test` na raiz e no `bootstrap/`; ShellCheck do `user_data` pronto; montagem do `.env` do `user_data` num container do Amazon Linux 2023.
- O checkout traz o repositório inteiro, e o `compute.tf` acha o `../docker-compose.prod.yml`.
- Nada fala com a AWS: sem credencial, sem `plan` e sem state.

### actionlint

actionlint 1.7.12, pela imagem oficial, que traz o ShellCheck 0.11.0 e confere também os scripts dos passos `run`.

### Rodar o mesmo no computador

No PowerShell, na raiz do repositório, com o Docker Desktop.

| Job | Como rodar |
|---|---|
| PHP | Com o dev no ar (`docs/nuvem/DOCKER.md`): `docker compose exec app vendor/bin/pint --test`, `docker compose exec app composer test` e `docker compose exec app composer audit`. A cobertura precisa do pcov, que só o CI instala |
| MySQL | `composer test:mysql`, com a receita do README (chega com a `feat/agendamentos`) |
| Front | Com Node 24 no computador: `npm ci`, `npm run build` e `npm audit --audit-level=high`. Só o build também roda no Docker: `docker compose run --rm assets` |
| Docker | Os comandos abaixo |
| Terraform | A função `tf` e os comandos do `docs/nuvem/TERRAFORM.md` (seções "Validar e testar sem conta da AWS", "Trivy" e "Como o user_data foi testado") |
| actionlint | O comando abaixo |

Imagens e Trivy, como no job `Docker`:

```powershell
docker build -f docker/php/Dockerfile --target app -t barberflow-app:local .
docker build -f docker/php/Dockerfile --target web -t barberflow-web:local .
docker save -o "$env:TEMP\barberflow-app.tar" barberflow-app:local
docker save -o "$env:TEMP\barberflow-web.tar" barberflow-web:local
docker run --rm -v "${env:TEMP}:/scan:ro" aquasec/trivy:0.76.2@sha256:8f6bb947dbc528902e11f2f5be52f7b519f6a39d5d148f41166f2040499768fb image --input /scan/barberflow-app.tar --scanners vuln --ignore-unfixed --severity CRITICAL --exit-code 1 --no-progress
docker run --rm -v "${env:TEMP}:/scan:ro" aquasec/trivy:0.76.2@sha256:8f6bb947dbc528902e11f2f5be52f7b519f6a39d5d148f41166f2040499768fb image --input /scan/barberflow-web.tar --scanners vuln --ignore-unfixed --severity CRITICAL --exit-code 1 --no-progress
Remove-Item "$env:TEMP\barberflow-app.tar", "$env:TEMP\barberflow-web.tar"
```

Sem vulnerabilidade crítica com correção, cada Trivy termina com a tabela zerada e código 0.

Workflows:

```powershell
docker run --rm -v "${PWD}:/repo:ro" -w /repo rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -color
```

Sem erro, o comando não mostra nada e termina com código 0.

## O deploy (`deploy.yml`)

### Quando roda

- **Versão nova:** push de uma tag `v*` (ex.: `v1.4.0`) num commit da `main`.
- **Rollback, ou deploy à mão:** Actions, Deploy, Run workflow, a partir da `main`. Com o `image_tag` preenchido, volta para uma versão que já está no ECR; vazio, faz o build do commit.

Toda rodada espera a aprovação do grupo no environment `production`. Um deploy de cada vez: a segunda rodada espera a primeira terminar, sem cancelar nada. Se uma terceira chegar enquanto a segunda espera, o GitHub cancela a segunda e fica só a mais nova na fila.

O GitHub não dispara o workflow quando mais de três tags chegam no mesmo push: crie e mande uma tag por vez.

```powershell
git fetch origin
git tag v1.4.0 origin/main
git push origin v1.4.0
```

### Passo a passo

Os comentários do `deploy.yml` numeram os seis passos principais: 1 entrar por OIDC, 2 build, Trivy e push, 3 conferir o compose, 4 gravar a tag no SSM, 5 instance refresh e 6 `/api/health`. A lista abaixo segue a ordem de execução, com as conferências entre eles.

1. Confere as variáveis do environment. Faltando uma, para citando o nome.
2. Baixa o código com o histórico inteiro e confere que o commit está na `main`. Só vai para a AWS o que passou por pull request e pelo CI.
3. Define a tag das imagens: os 12 primeiros caracteres do commit ou, no rollback, o `image_tag` digitado, conferido antes de qualquer uso: 12 a 40 caracteres, só `0-9` e `a-f`, e o começo de um commit que está na `main`. Só o deploy publica tags com o começo do commit; a imagem feita à mão no "Primeiro deploy" do `docs/nuvem/TERRAFORM.md` leva o prefixo `manual-`.
4. **Entra na AWS por OIDC.** A action `aws-actions/configure-aws-credentials` pede um token ao GitHub e troca o token por credenciais de 1 hora da role `AWS_DEPLOY_ROLE_ARN`, na região `AWS_REGION`. Nenhuma chave da AWS fica guardada no GitHub, e o ID da conta sai mascarado no log.
5. **Confere o `docker-compose.prod.yml`.** Compara o `sha256sum` do arquivo do commit com a tag `ComposeFileSha256` do launch template que o Auto Scaling usa (`aws ec2 describe-launch-templates`). Se forem diferentes, para pedindo o `terraform apply` (seção "Ordem com o terraform apply"). Vem antes do build, para falhar cedo, sem publicar imagem.
6. Confere que não há instance refresh em andamento, como o que o `apply` começa. A AWS não aceita dois ao mesmo tempo, e trocar a tag no meio de um misturaria versões.
7. Procura as imagens no ECR (`aws ecr describe-images`). No rollback, as duas precisam existir com a tag. Num deploy repetido do mesmo commit, o build pula a imagem que já está lá: as tags do ECR são imutáveis e recusariam a mesma tag de novo. Como só o deploy publica essas tags, a imagem achada é de um deploy anterior, que já passou pelo Trivy, e o resumo da rodada diz qual foi reaproveitada.
8. **Build, Trivy e push**, só sem `image_tag`: login no ECR; build limpo das imagens `app` e `web`, sem o cache do CI e com o BuildKit fixado, em arquivos no runner; o mesmo Trivy do job `Docker` do CI nas duas (`--ignore-unfixed`, CRITICAL); e só então o push, com a tag do commit. Nunca `latest`. Uma vulnerabilidade crítica com correção publicada para o deploy antes do push: nada vai para o ECR, e a `image_tag` continua a anterior. Assim, um alerta publicado entre o merge e a tag também barra a versão.
9. **Grava a tag no SSM:** `aws ssm put-parameter --overwrite` no parâmetro `IMAGE_TAG_PARAMETER` (`/barberflow/prod/image_tag`). A tag anterior fica guardada para o resumo e para o aviso de rollback.
10. **Instance refresh:** `aws autoscaling start-instance-refresh` no `ASG_NAME`, `Rolling`, com pelo menos 50% das instâncias no ar e 300 s de aquecimento, as mesmas preferências do `compute.tf`. Cada instância nova lê a `image_tag` do SSM quando sobe. As credenciais são renovadas antes da espera, que vai até 45 minutos. O deploy falha se o refresh terminar em `Failed`, `Cancelled` ou em rollback, ou se passar do limite (aí o refresh continua na AWS).
11. **Testa o `/api/health`:** `<APP_URL>/api/health` até responder 200, em 20 tentativas, uma a cada 15 s. O `/api/health` confere banco e cache. O `/up`, que o ALB olha, é raso e não serve para isso.
12. Resumo da rodada: a tag anterior, a nova e o rollback pronto para copiar. Se algo falhar depois da troca da tag, um aviso mostra a tag para voltar. No fim, sai do ECR (`docker logout`).

Na prod, com 2 instâncias, conte com uns 30 minutos do push da tag ao `/api/health` (estimativa, ainda não medida): build, Trivy, uma instância trocada de cada vez e os 300 s de aquecimento de cada uma.

**Primeiro deploy:** enquanto o Auto Scaling está com 0 instância (`docs/nuvem/TERRAFORM.md`, "Primeiro deploy"), o deploy publica as imagens e a `image_tag`, deixa um aviso e pula o instance refresh e o `/api/health`. As instâncias nascem quando o `apply` sobe a capacidade.

### Rollback

Use quando a versão nova tem um defeito e a anterior funciona com o banco como ele está.

1. Ache a tag anterior. Ela está no resumo do último deploy (`image_tag: <anterior> -> <nova>`). Com o perfil do grupo na AWS CLI, também dá para listar:

   ```powershell
   aws ssm get-parameter-history --name /barberflow/prod/image_tag --query "Parameters[-5:].[LastModifiedDate,Value]" --output table
   aws ecr describe-images --repository-name barberflow-prod-app --query "sort_by(imageDetails,&imagePushedAt)[-5:].[imagePushedAt,imageTags[0]]" --output table
   ```

2. Actions, Deploy, Run workflow, a partir da `main`, com o `image_tag` preenchido.
3. Aprove no environment `production`.

O rollback faz os passos do deploy menos o build e o Trivy: confere que a tag é o começo de um commit da `main` e que as duas imagens existem no ECR com essa tag, confere o compose, grava a tag, faz o instance refresh e testa o `/api/health`. A imagem passou pelo Trivy quando foi publicada, e um alerta novo não trava a volta numa emergência.

- Só volta para imagem que ainda está no ECR: cada repositório guarda as `ecr_keep_tagged_images` imagens com tag mais novas (10 por padrão).
- Só volta para commit da `main`: o `image_tag` de um commit de outra branch, ou que não é commit nenhum, para com "image_tag fora da main".
- Volta só a imagem. O compose e o `user_data` continuam os do launch template, então o compose atual precisa funcionar com a imagem antiga (seção "Ordem com o terraform apply").
- O banco não volta (próxima seção).
- Sem o GitHub (fora do ar, por exemplo), o mesmo rollback se faz à mão: grave a tag com `aws ssm put-parameter --name /barberflow/prod/image_tag --value <tag> --overwrite` e faça o instance refresh da seção "Trocar a capacidade e as instâncias" do `docs/nuvem/TERRAFORM.md`. É também o caminho para voltar a uma imagem publicada à mão (tag `manual-...`), que o Deploy recusa.

### Migrations: só para frente

- O container `app` roda `php artisan migrate --force --isolated` quando sobe (`RUN_MIGRATIONS=true` no SSM). A primeira instância nova do deploy aplica as migrations da versão nova, antes do teste do `/api/health`.
- O rollback troca só a imagem: o que as migrations da versão nova fizeram continua no banco. Nem o deploy nem o rollback rodam `migrate:rollback`.
- Durante o instance refresh as duas versões rodam juntas, e o rollback volta a versão anterior com o banco novo. Por isso, toda migration precisa funcionar com a versão anterior do código:
  - coluna nova entra com `nullable()` ou com um `default()`;
  - renomear ou apagar coluna (ou tabela) leva duas versões: a primeira para de usar, e só a seguinte apaga;
  - nada de mudar o tipo de uma coluna que a versão anterior lê.
- **Voltar o schema ou os dados é restore do banco**, nunca rollback de imagem: `docs/nuvem/BACKUP.md`, "Restore de um minuto do passado", com o minuto de antes do deploy. O restore cria um banco novo e leva o RTO do `BACKUP.md` (45 a 60 minutos, estimado).
- Antes de um deploy com migration que apaga dado, tire um snapshot do banco à mão, com o perfil do grupo:

  ```powershell
  aws rds create-db-snapshot --db-instance-identifier barberflow-prod-db --db-snapshot-identifier barberflow-prod-antes-v1-4-0
  ```

  O snapshot fica guardado, e cobrando, até alguém apagar. Para voltar dele: `docs/nuvem/BACKUP.md`, "Restore de um snapshot".

### Ordem com o terraform apply

O `docker-compose.prod.yml` e o `user_data` vão dentro do launch template, e só o `apply` leva uma mudança neles às instâncias. O deploy troca só a imagem (`docs/nuvem/TERRAFORM.md`, "Launch template e user_data").

Quando um pull request muda o `docker-compose.prod.yml` (variável nova, healthcheck, comando ou serviço):

1. Faça o merge na `main`.
2. Rode o `plan` e o `apply` da prod a partir da `main` atualizada, sem mudança local no compose (`docs/nuvem/TERRAFORM.md`, "Escolher o ambiente" e "Aplicar na AWS"). O `apply` cria uma versão nova do launch template e começa sozinho um instance refresh, com a imagem que já está no ar.
3. Espere esse refresh terminar:

   ```powershell
   aws autoscaling describe-instance-refreshes --auto-scaling-group-name barberflow-prod-app --max-records 1 --query "InstanceRefreshes[0].[Status,PercentageComplete]"
   ```

4. Crie a tag `v*` (deploy da imagem nova).

- Tag antes do `apply`: o deploy para no passo do compose com "Falta o terraform apply", sem publicar nada. Tag durante o refresh do `apply`: para com "Instance refresh em andamento". Nos dois casos, depois do passo que faltou, rode de novo pelo "Re-run all jobs" da rodada.
- O compose novo precisa funcionar também com a imagem anterior: o `apply` troca as instâncias antes do deploy, e o rollback usa o compose que está no launch template.
- Mudança no Terraform que não toca no compose nem no `user_data` (escala, alarme, parâmetro) não depende do deploy. Parâmetro novo do SSM só chega às instâncias com um instance refresh, como o do próximo deploy.
- Não rode `apply` e deploy ao mesmo tempo.

## A role de deploy (`terraform/cicd.tf`)

### Como o GitHub entra na AWS

- O job do deploy pede ao GitHub um token assinado (um JWT), que diz de qual repositório e de qual environment ele vem. A AWS confere a assinatura pelo provedor OIDC `token.actions.githubusercontent.com` e troca o token por credenciais temporárias da role `barberflow-<ambiente>-deploy` (`sts:AssumeRoleWithWebIdentity`). Nenhuma chave de acesso fica guardada no GitHub.
- **A role só existe onde o environment está protegido.** Ela nasce só com `create_deploy_role = true` (a prod, no exemplo; o padrão é `false`). A trust confere o nome do environment, mas quem protege o environment é o GitHub: quando um workflow cita um environment que ainda não existe, o GitHub cria esse environment sem nenhuma proteção, e qualquer pessoa que pode editar workflows no repositório consegue fazer isso (documentação do GitHub, "Managing environments for deployment", conferida em 14/11/2026). Sem as proteções, um workflow de qualquer branch assumiria a role. Por isso o environment `production` é configurado antes do `apply` que cria a role (seção "Primeiro deploy", mais abaixo), e a homolog, que não tem environment no GitHub, fica sem role.
- **Provedor OIDC:** a AWS aceita um só por conta para esse endereço. O ambiente com `create_github_oidc_provider = true` cria (a prod, no exemplo); um ambiente com a role e `false` lê o que existe, por um data source. A homolog do exemplo não tem role e não lê o provedor, então a ordem dos `apply` dos dois ambientes não importa. Se a homolog ganhar uma role na mesma conta da prod, aplique a prod antes. Se a conta já tiver o provedor (criado à mão ou por outro projeto), use `false` também na prod.
- **Sem thumbprint:** a AWS confere o certificado do GitHub pela própria lista de autoridades certificadoras e não usa o thumbprint. O provider AWS 6.73 deixa o `thumbprint_list` opcional (documentação da AWS, "Obtain the thumbprint for an OpenID Connect identity provider", e do provider, conferidas em 13/11/2026).
- **Trust:** uma declaração só, com `StringEquals` no `aud` (`sts.amazonaws.com`) e no `sub`. O `sub` precisa ser o do environment `production` do repositório. Pull request, push numa branch e job sem `environment: production` mandam outro `sub` e são recusados. Um job com `environment: production` manda o mesmo `sub` de qualquer branch: quem barra a branch errada é a regra "Deployment branches and tags" do environment, e quem aprova cada deploy são os revisores dele.

| Formato do `sub` | Valor | Quando o GitHub usa |
|---|---|---|
| Com os nomes | `repo:josecoene/barber-shop:environment:production` | Repositório criado até 15/07/2026, que não ligou o formato imutável |
| Imutável | `repo:josecoene@<ID do dono>/barber-shop@<ID do repositório>:environment:production` | Repositório criado, renomeado ou transferido depois de 15/07/2026, ou que ligou o formato imutável |

Fonte: documentação do GitHub, "OpenID Connect reference" (https://docs.github.com/en/actions/reference/security/oidc), conferida em 13/11/2026.

- A role aceita sempre o formato com os nomes e, com `github_repository_owner_id` e `github_repository_id` preenchidos (os dois ou nenhum), também o imutável.
- O primeiro commit do repositório é de 24/09/2026, depois de 15/07/2026: o GitHub deve mandar o formato imutável. **Preencha os dois IDs no `envs/prod.tfvars` antes do primeiro deploy.** Sem eles, o deploy para no passo do OIDC (seção "Problemas comuns"). Os IDs saem de `gh api repos/josecoene/barber-shop --jq '.owner.id, .id'` ou dos campos `owner.id` e `id` de https://api.github.com/repos/josecoene/barber-shop.
- Com os IDs, um repositório apagado e criado de novo com o mesmo nome (por outra pessoa, por exemplo) ganha IDs novos e não assume a role.
- O nome do environment no `sub` é o do GitHub, com as mesmas maiúsculas: `Production` não é `production`.
- A saída `github_deploy_subjects` mostra os valores que a role aceita.

### O que a role pode

| Para quê | Ações | Onde vale |
|---|---|---|
| Login no ECR | `ecr:GetAuthorizationToken` | `*`, porque a ação não aceita outro recurso |
| Push e consulta das imagens | `ecr:BatchCheckLayerAvailability`, `ecr:InitiateLayerUpload`, `ecr:UploadLayerPart`, `ecr:CompleteLayerUpload`, `ecr:PutImage`, `ecr:BatchGetImage` e `ecr:DescribeImages` | Só os 2 repositórios do ambiente |
| Ler e gravar a tag no ar | `ssm:GetParameter` e `ssm:PutParameter` | Só `/barberflow/<ambiente>/image_tag`, que é String (sem `kms:Decrypt`) |
| Trocar as instâncias | `autoscaling:StartInstanceRefresh` | Só o Auto Scaling do app |
| Consultas só de leitura | `autoscaling:DescribeAutoScalingGroups`, `autoscaling:DescribeInstanceRefreshes` e `ec2:DescribeLaunchTemplates` | `*`: a AWS não aceita recurso nessas três (Service Authorization Reference) |

- Nada de `iam:PassRole`, do state (S3), dos outros parâmetros do SSM (senha do banco e `APP_KEY`), do KMS nem do STS. O deploy confere o compose pela tag do launch template, sem ler o state, que guarda a senha do RDS.
- Sessão de 1 hora (`max_session_duration`). O deploy pede credenciais novas antes de esperar o instance refresh.
- Pela documentação da AWS, o `StartInstanceRefresh` confere `ec2:RunInstances` e `iam:PassRole` de quem chama quando o Auto Scaling usa a versão `$Latest` ou `$Default` do launch template. O `compute.tf` usa a versão exata, por isso a role não tem as duas (veja "Problemas comuns" se a AWS pedir).
- As duas políticas ficam em `jsonencode()` nos `locals` (`local.deploy_assume_role_policy` e `local.deploy_role_policy`), para o teste ler.

### O que os testes conferem

`terraform/tests/cicd.tftest.hcl`, com 12 runs e o provider simulado:

- o padrão (`create_deploy_role = false`, o da homolog): nenhuma role, nenhuma política e nenhuma leitura do provedor OIDC; as saídas da role nulas ou vazias, e as do ECR e do `image_tag` presentes;
- trust: com a role, o provedor OIDC é lido da conta por padrão; uma declaração só, `Allow` para o provedor (`Federated`), com `sts:AssumeRoleWithWebIdentity`; só `StringEquals`, com o `aud` e o `sub` do environment `production` do repositório; nenhum curinga;
- com os dois IDs, a trust aceita os dois formatos de `sub`, e a saída `github_deploy_subjects` mostra os dois;
- o provedor OIDC só é criado com `create_github_oidc_provider = true`, sem `thumbprint_list` e só com o `aud` do STS, e a trust aponta para ele;
- política: a lista exata de ações; toda declaração é um `Allow` com `Action` e `Resource`, sem `NotAction`, `NotResource` nem `Condition`; nenhuma ação do IAM, do S3, do DynamoDB, do KMS nem do STS; nenhum curinga nas ações e nos ARNs; `Resource "*"` só no login do ECR e nas três consultas de leitura; o ECR só nos 2 repositórios; o SSM só no `image_tag`; o refresh só no Auto Scaling;
- duas travas que leem o texto dos `.tf`. A primeira confere, na pasta `terraform/` e no `bootstrap/`, a lista exata dos recursos que ligam política a uma role (os tipos `aws_iam_role_polic*` e o `aws_iam_policy_attachment`): a política da role de deploy, no `cicd.tf`, e a política e o anexo da role das instâncias, no `iam.tf`. Um anexo novo faz o teste falhar, cite ele a role pela referência, por um `local` ou pelo nome escrito (`"${local.name_prefix}-deploy"`). A segunda confere que só a política própria cita a role de deploy pela referência. O run `iam_role` do `dados_seguranca.tftest.hcl` já recusa, em qualquer `.tf` da pasta, a `managed_policy_arns` e a `inline_policy`, que põem política dentro da própria role;
- a prod, com os nomes e o parâmetro da prod, e as saídas;
- recusa repositório com curinga ou sem o dono, environment com curinga, um ID sem o outro e ID que não é número.

Em 14/11/2026, 35 defeitos de propósito no `cicd.tf`, no `variables.tf`, no `outputs.tf` e em arquivos novos, numa cópia da pasta, fizeram o teste falhar. Entre eles: `sub` com curinga (`StringLike`), trust sem o `aud`, `sub` da branch `main` no lugar do environment, provedor OIDC sempre criado ou com thumbprint, role criada por padrão ou em todo ambiente, provedor lido sem a role, `iam:PassRole` na role, `PutParameter` em todos os parâmetros, push em qualquer repositório, `ecr:*`, `StartInstanceRefresh` em qualquer Auto Scaling e poder de administrador ligado à role de oito jeitos: a `AdministratorAccess` anexada pela referência, por um `local`, pelo nome escrito, com os rótulos sem aspas, por `aws_iam_policy_attachment`, por `aws_iam_role_policy_attachments_exclusive` e por um anexo no `bootstrap/`, e uma política inline com `Action "*"` presa à role pelo nome. A `managed_policy_arns` na role faz falhar o run `iam_role` do `dados_seguranca.tftest.hcl`.

### Variáveis do Terraform

| Variável | Padrão | Homolog | Prod | Para que serve |
|---|---|---|---|---|
| `create_deploy_role` | `false` | `false` | `true` | Cria a role de deploy e a política dela, só onde o environment do GitHub já está protegido |
| `create_github_oidc_provider` | `false` | `false` | `true` | Cria o provedor OIDC do GitHub na conta (um por conta) |
| `github_repository` | `josecoene/barber-shop` | igual | igual | Repositório (dono/nome) cujo deploy assume a role |
| `github_environment` | `production` | `homolog` | `production` | Environment do GitHub aceito no `sub` |
| `github_repository_owner_id` | vazio | vazio | ID do dono (preencha) | Formato imutável do `sub` |
| `github_repository_id` | vazio | vazio | ID do repositório (preencha) | Formato imutável do `sub`, junto com o anterior |

A homolog do exemplo fica sem role: o `deploy.yml` só publica na prod, e o environment `homolog` não existe no GitHub. Para dar uma role a ela (`create_deploy_role = true`), crie antes o environment `homolog` com as mesmas proteções da seção 1 abaixo. A role da homolog aceita só esse environment, e o `production` nunca a assume.

## Configurar o GitHub (uma vez, pelo José)

O repositório é do José, e estas telas pedem permissão de administrador. Pela documentação do GitHub (conferida em 14/11/2026), num repositório público tudo deste capítulo é grátis. Num repositório privado, o environment e a regra de branches dele pedem o GitHub Pro, o Team ou o Enterprise, e a aprovação obrigatória (Required reviewers) fica só para o Enterprise.

- Sem a regra de branches do environment, não crie a role de deploy: use `create_deploy_role = false` no `envs/prod.tfvars` e publique à mão (`docs/nuvem/TERRAFORM.md`, "Primeiro deploy"), porque um workflow de qualquer branch assumiria a role.
- Com a regra de branches e sem a aprovação obrigatória (repositório privado no Pro ou no Team), o deploy roda sem a aprovação do grupo: quem tem escrita no repositório consegue rodar o Deploy à mão a partir da `main`, com o código que passou pelo pull request.

### 1. Environment production

Crie antes do `apply` que cria a role de deploy (seção 5). Quando um workflow cita um environment que não existe, o GitHub cria esse environment sem nenhuma proteção.

Settings, Environments, New environment, `production`:

- **Required reviewers:** quem do grupo aprova o deploy, com "Prevent self-review" marcado (quem disparou não aprova o próprio deploy).
- **Deployment branches and tags:** "Selected branches and tags", com a branch `main` e a tag `v*`. Um workflow rodando de outra branch não entra no environment e não recebe o token da role.
- **Environment variables:** as 7 da tabela abaixo.

### 2. Variáveis do environment

Depois do `apply` da prod, `tfaws output github_environment_variables` mostra as 7 prontas para copiar (funções `tf` e `tfaws` no `docs/nuvem/TERRAFORM.md`).

| Variável | Saída do Terraform | Exemplo na prod |
|---|---|---|
| `AWS_DEPLOY_ROLE_ARN` | `deploy_role_arn` | `arn:aws:iam::<ID da conta>:role/barberflow-prod-deploy` |
| `AWS_REGION` | variável `region` | `us-east-1` |
| `ASG_NAME` | `asg_name` | `barberflow-prod-app` |
| `APP_URL` | `app_url` | `http://barberflow-prod-alb-<número>.us-east-1.elb.amazonaws.com`, ou `https://<domínio>` com certificado |
| `ECR_APP_REPOSITORY` | `ecr_repository_names["app"]` | `barberflow-prod-app` |
| `ECR_WEB_REPOSITORY` | `ecr_repository_names["web"]` | `barberflow-prod-web` |
| `IMAGE_TAG_PARAMETER` | `image_tag_parameter_name` | `/barberflow/prod/image_tag` |

- Entram como variáveis (Variables), e não como segredos (Secrets): nenhuma é segredo. O ID da conta, no ARN, sai mascarado no log pela action.
- Com o `gh` instalado, dá para gravar pela linha de comando: `gh variable set ASG_NAME --env production --body barberflow-prod-app` (uma por variável).
- Quando o domínio chegar (`docs/nuvem/TERRAFORM.md`, "HTTPS com domínio próprio"), troque o `APP_URL`.

### 3. Proteção da main

Settings, Rules, Rulesets, New ruleset, New branch ruleset:

- nome `main`, "Enforcement status" em Active e, em "Target branches", a branch padrão;
- lista de bypass vazia: nem o administrador faz push direto na `main`;
- "Restrict deletions" e "Block force pushes";
- "Require a pull request before merging", com 1 aprovação e "Dismiss stale pull request approvals when new commits are pushed";
- "Require status checks to pass", com os checks `PHP`, `MySQL`, `Front`, `Docker`, `Terraform` e `actionlint` e "Require branches to be up to date before merging". Os checks aparecem na lista depois que o CI roda uma vez, no primeiro pull request que tiver o `ci.yml` (enquanto ele não estiver na `main`, só o de uma branch que já o traga, como a `feat/ci-cd`, a `feat/observabilidade` ou a `feat/entrega-final`).

E uma regra para as tags de versão, em New ruleset, New tag ruleset: alvo `v*`, com "Restrict creations", "Restrict updates" e "Restrict deletions", e só o administrador do repositório no bypass. Assim só o José publica uma versão, e ninguém move uma tag de versão para outro commit.

### 4. Actions e Dependabot

- Settings, Actions, General, Workflow permissions: "Read repository contents and packages permissions", com "Allow GitHub Actions to create and approve pull requests" desmarcado. O `ci.yml` e o `deploy.yml` já pedem só o que usam; isto vale para workflows novos.
- Na mesma tela, na política das actions, ligue a exigência de SHA completo nas actions. As dos dois workflows já estão assim.
- Na seção de segurança do código das Settings (Code security, que em alguns repositórios aparece como Advanced Security), ligue o Dependabot alerts e o Dependabot security updates. O `dependabot.yml` cuida das atualizações de versão.

### 5. Primeiro deploy

1. Antes de existir a role: o environment `production` com os revisores e as branches da seção 1 (as variáveis ficam para o passo 3) e as regras da `main` e das tags `v*` da seção 3.
2. `apply` da prod com `create_deploy_role = true`, os dois IDs no `envs/prod.tfvars` e a capacidade em 0 (`docs/nuvem/TERRAFORM.md`, "Primeiro deploy", passo 1). Ele cria também o provedor OIDC e a role de deploy.
3. As 7 variáveis do environment (seção 2), da saída `github_environment_variables`, e o Dependabot e as permissões do Actions (seção 4).
4. `APP_KEY` no SSM (`docs/nuvem/TERRAFORM.md`, "Preencher o APP_KEY").
5. Tag `v*` na `main` e aprovação no environment: o deploy publica as imagens e a `image_tag` e pula o instance refresh.
6. `apply` com a capacidade do `.tfvars` (passo 4 do "Primeiro deploy" do `docs/nuvem/TERRAFORM.md`) e a conferência do passo 5 dele, mais o `curl.exe -i <APP_URL>/api/health`.

## Dependabot (`.github/dependabot.yml`)

| Ecossistema | Pasta | Pull requests abertos, no máximo | O que atualiza |
|---|---|---|---|
| `composer` | `/` | 5 | `composer.json` e `composer.lock` |
| `npm` | `/` | 5 | `package.json` e `package-lock.json` |
| `github-actions` | `/` | 3 | SHA e comentário da versão das actions dos workflows |
| `docker` | `/docker/php` | 3 | Imagens do `Dockerfile` (`php`, `composer`, `node` e `nginx`) |
| `docker-compose` | `/` | 3 | Imagens dos `docker-compose*.yml` da raiz: dev, homolog (MySQL, Redis e Mailpit), monitoramento e k6. Nas fixadas também pelo digest (Alloy e cAdvisor), troca o digest junto |
| `terraform` | `/terraform` e `/terraform/bootstrap` | 3 | Provider AWS e os `.terraform.lock.hcl` |

- Roda toda segunda, às 06:00 de Brasília.
- As atualizações menores (minor e patch) de cada ecossistema vêm juntas num pull request; as maiores (major), uma por pull request.
- Versão nova só é proposta 7 dias depois de publicada (`cooldown`): tempo para a comunidade achar uma versão adulterada, como a do Trivy em março de 2026. Os alertas de segurança não esperam.
- No Dockerfile, o PHP fica na 8.4 (só correções 8.4.x), o Node na 24 e o Nginx na 1.30 (estável; as versões com o segundo número ímpar, como a 1.31, são a linha de testes). Nos compose, o Node e o Nginx do dev seguem a mesma regra, e o MySQL fica na 8.4 (só correções 8.4.x), a linha do RDS. Trocar de linha é decisão do grupo, feita à mão.
- O bloco `docker-compose` lê os arquivos da raiz cujo nome casa com a regra do Dependabot (`(docker-)?compose(-\w+)?(\.[\w-]+)?\.ya?ml`, conferida no código do `dependabot-core` em 14/11/2026), o que inclui o `docker-compose.monitoring.yml` e o `docker-compose.carga.yml`. As imagens construídas no computador (`barberflow-app` e `barberflow-web`) ficam de fora pelo `ignore`, e o `docker-compose.prod.yml` não tem imagem fixa (as dele vêm do ECR, por variável). Validado só no código: a primeira rodada no GitHub mostra os pull requests.
- Os commits saem como `chore[Deps]: ...`, `chore[CI]: ...`, `chore[Docker]: ...` e `chore[Terraform]: ...`, no formato do repositório.
- Cada pull request do Dependabot passa pelo CI inteiro antes do merge, como qualquer outro.
- Depois de um pull request do `terraform`, confira os hashes das quatro plataformas no lock (`docs/nuvem/TERRAFORM.md`, "Atualizar versões").

## Atualizar versões

O Dependabot não vê estas, que se atualizam à mão:

| O quê | Onde | Como conferir a versão nova |
|---|---|---|
| Imagens do Trivy, ShellCheck, Amazon Linux e actionlint | Bloco `env` do `ci.yml` (o Trivy também no do `deploy.yml`, e os comandos do `docs/nuvem/TERRAFORM.md`) | `docker buildx imagetools inspect <imagem>:<tag>` mostra o digest; troque a tag e o digest juntos |
| BuildKit (`BUILDKIT_IMAGE`) | Bloco `env` do `ci.yml` e do `deploy.yml`, o mesmo valor nos dois | `docker buildx imagetools inspect moby/buildkit:buildx-stable-1` mostra o digest da versão que o Buildx usa como estável; ache a tag `v*` com o mesmo digest e troque a tag e o digest juntos, com 7 dias de publicada, como o `cooldown` do Dependabot |
| MySQL do job `MySQL` | `services.mysql.image` do `ci.yml`, na mesma versão dos compose (que o Dependabot atualiza) | O mesmo `imagetools inspect` |
| Composer do CI | `tools` do setup-php, nos jobs `PHP` e `MySQL` | A mesma versão do Dockerfile; o SHA-256 está em `https://getcomposer.org/download/<versão>/composer.phar.sha256sum` |
| Terraform 1.13.3 | `terraform_version` do `ci.yml` e as funções do `docs/nuvem/TERRAFORM.md` | Notas da versão nova |

O `php-version: '8.4'` e o `node-version: '24'` pegam sozinhos a correção mais nova da linha.

Para conferir à mão o SHA de uma action: `git ls-remote https://github.com/actions/checkout refs/tags/v7.0.1`. Numa tag anotada, como as do setup-php, aparecem duas linhas; o commit é o da linha que termina em `^{}`. O SHA vai no `uses:` e a versão no comentário da mesma linha.

Versões em uso, conferidas em 13/11/2026 (todas as mais novas estáveis nessa data):

| Action | Versão | SHA do commit |
|---|---|---|
| `actions/checkout` | v7.0.1 | `3d3c42e5aac5ba805825da76410c181273ba90b1` |
| `shivammathur/setup-php` | 2.40.0 | `eb7c497e18156a6bbabfef1d3a82760b9eda3962` |
| `actions/cache` | v6.1.0 | `55cc8345863c7cc4c66a329aec7e433d2d1c52a9` |
| `actions/upload-artifact` | v7.0.2 | `cf430e030ddbb5b0abf93d22962f4752f3646cd9` |
| `actions/setup-node` | v7.1.0 | `949feb2413d6458794dcd2491c4babbbce0c15c1` |
| `docker/setup-buildx-action` | v4.4.1 | `f87e5991a6d7451dcb8d9637bfbc97413f497069` |
| `docker/build-push-action` | v7.4.0 | `c3c9e263c25d99ce0380d002d59b67737d91b0dc` |
| `hashicorp/setup-terraform` | v4.0.1 | `dfe3c3f87815947d99a8997f908cb6525fc44e9e` |
| `aws-actions/configure-aws-credentials` | v6.3.0 | `e1253824e5c10ff9df46874f81ed3ec929e19cfd` |

| Imagem ou ferramenta | Versão |
|---|---|
| Trivy | `aquasec/trivy:0.76.2`, com digest |
| BuildKit | `moby/buildkit:v0.34.2`, com digest (a `buildx-stable-1` em 14/11/2026; a v0.35.0 saiu em 11/11/2026 e ainda não passou dos 7 dias) |
| ShellCheck | `koalaman/shellcheck:v0.11.0`, com digest |
| Amazon Linux | `amazonlinux:2023.12.20261102.0`, com digest |
| actionlint | `rhysd/actionlint:1.7.12`, com digest |
| MySQL do job | `mysql:8.4.13`, com digest |
| Composer | 2.11.2, com o SHA-256 do `composer.phar` |
| Terraform | 1.13.3 |

## Situação conferida em 14/11/2026

O que rodou no computador, em containers, sem GitHub e sem AWS:

| O quê | Resultado |
|---|---|
| actionlint 1.7.12 nos dois workflows | Nenhum erro |
| Scripts dos passos do `deploy.yml`, com um `aws`, um `curl`, um `sleep` e um `docker` falsos, num container do Amazon Linux 2023 (o roteiro do teste não ficou no repositório) | 61 cenários conferidos: tag do commit e recusa de `image_tag` com comando, maiúsculas, prefixo `manual-`, commit fora da `main` ou que não é commit; commit fora da `main`; compose diferente ou sem a tag; Auto Scaling inexistente; refresh em andamento; imagem faltando no rollback; erro da AWS; Trivy nas imagens do build, com os parâmetros do CI, e o deploy parando antes do push com CRITICAL; push só da imagem que o Trivy leu; `put-parameter` sem `--type`; refresh em `Failed`, `Cancelled` e rollback; `/api/health` com 503 até o fim; o resumo do primeiro deploy, do deploy repetido e com a versão anterior feita à mão; o mesmo Trivy e o mesmo BuildKit nos dois workflows |
| BuildKit `moby/buildkit:v0.34.2` com digest | O digest é o mesmo da `buildx-stable-1`, e o `buildkitd --version` da imagem mostra a v0.34.2 |
| `.github/dependabot.yml` contra o JSON Schema do SchemaStore | Válido; de novo com o bloco `docker-compose` (`check-jsonschema` 0.39.0), que recusa um nome de ecossistema errado |
| `terraform fmt -check`, `validate` e `test` | Sem diferença de formatação; configuração válida; `57 passed` na raiz (12 do CI/CD) e `1 passed` no bootstrap |
| 35 defeitos de propósito no `cicd.tf`, no `variables.tf`, no `outputs.tf` e em arquivos novos, numa cópia da pasta (seção "O que os testes conferem") | O teste falhou em todos; sem defeito, passou |
| `trivy config` com o `.trivyignore.yaml` | Nenhum alerta |
| ShellCheck do `user_data` e montagem do `.env` no Amazon Linux 2023 | Nenhum aviso; `Sucesso: 15 conferências passaram.` |
| Build dos alvos `app`, `web` e `dev` e Trivy (`--ignore-unfixed`, CRITICAL) nas imagens `app` e `web` | Builds sem erro; nenhuma vulnerabilidade crítica com correção |
| `app` e `web` novas numa rede de teste, sem banco | Containers saudáveis; `/up`, `/login` e `/services` com 200 |
| Passos do job `PHP` nesta base (PHP 8.4 com pcov e sem intl, Composer 2.11.2) | Pint passou; 44 testes passaram, com cobertura e o `clover.xml`; o `composer audit` falhou (abaixo) |
| Passos do job `Front` (Node 24.22.3) | `npm ci` e build sem erro; `npm audit` sem nenhuma vulnerabilidade |
| Jobs `PHP` e `MySQL` com o código da `feat/agendamentos` e um MySQL 8.4.13 com as credenciais do CI | Pint passou; SQLite com 475 testes passando, 3 pulados e 1 falha; MySQL com 479 testes e a mesma falha (abaixo) |

Dois pontos deixavam o CI vermelho nessa data, os dois da trilha da aplicação, e não do pipeline. Os dois foram resolvidos depois:

- **`composer audit`:** o alerta GHSA-63hm-g96r-2m2q (severidade baixa, publicado em 13/11/2026) atingia o `laravel/framework` antes da 13.40.0, e o `composer.lock` estava na 13.33.0. Em 15/11/2026, com o OK do Leonam, o `laravel/framework` foi para a 13.40.0, dentro do `^13.17`, e o `composer audit` ficou sem alerta, também no `composer audit --locked` de uma cópia limpa, como no CI (`docs/RELATORIO-TECNICO.md`, seção 4.12).
- **`Vite manifest not found`:** um teste do `RegistrationTest` da `feat/agendamentos` abria a página de erro 403 sem `withoutVite()`. No computador ele passava porque existe o `public/build`; no CI, que não faz o build, falhava. O teste ganhou o `withoutVite()` na `feat/lembretes-fidelidade` (commit `6e89adf`), e a rodada igual à do CI, numa cópia sem `public/build`, passa (747 testes passando e os 11 de trava pulados, como no SQLite).

Com o ruleset da seção "Proteção da main" ligado, um check `PHP` vermelho barra o merge: as duas correções precisam estar na branch do pull request, e as duas estão na `feat/entrega-final`.

## Problemas comuns

- **`Not authorized to perform sts:AssumeRoleWithWebIdentity`** no passo "Entra na AWS (OIDC)": o `sub` ou o `aud` do token não estão na trust. Confira os dois IDs no `envs/prod.tfvars` (e o `apply` depois de preencher), o nome do environment igual ao do GitHub, com as mesmas maiúsculas, e se o job continua com `environment: production`. A saída `github_deploy_subjects` mostra o que a role aceita.
- **`No OpenIDConnect provider found in your account`** no deploy, ou `reading IAM OIDC Provider (...): couldn't find resource` no `plan` de um ambiente com a role e `create_github_oidc_provider = false`: o provedor OIDC não existe na conta. Aplique antes o ambiente com `create_github_oidc_provider = true`.
- **`Variável faltando`**: crie a variável citada no environment `production` (seção "Variáveis do environment"). Se a saída `deploy_role_arn` está nula, o `apply` da prod rodou com `create_deploy_role = false` e a role não existe.
- **`Commit fora da main`**: a tag aponta para um commit que não passou pela `main`, ou o Run workflow partiu de outra branch. Faça o merge pelo pull request e crie a tag a partir da `main`.
- **`Falta o terraform apply`**: o `docker-compose.prod.yml` do commit não é o do launch template. Siga "Ordem com o terraform apply". Se o `apply` foi feito e o erro continua, ele rodou com o compose diferente do commit (mudança local sem commit, por exemplo): rode o `apply` de novo a partir da `main` limpa.
- **`Instance refresh em andamento`**: espere o refresh terminar e rode o deploy de novo.
- **`Tag não está no ECR`** ou **`image_tag inválida`** no rollback: a tag foi digitada errada ou a limpeza do ECR já apagou a imagem. Liste as tags com os comandos da seção "Rollback".
- **`image_tag fora da main`** no rollback: a tag não é o começo de um commit da `main` (um commit de outra branch, uma tag `manual-...` feita à mão ou um valor qualquer). Para voltar a uma imagem feita à mão, use o caminho sem o GitHub da seção "Rollback".
- **`Instance refresh terminou em Failed`** (ou `Cancelled`): as instâncias novas não ficaram saudáveis no ALB. Veja o motivo no log da subida e no CloudWatch (`docs/nuvem/TERRAFORM.md`, "Entrar na instância", "Logs no CloudWatch" e "Problemas comuns"). A `image_tag` já aponta para a versão nova: corrija ou faça o rollback para a tag que o aviso mostra.
- **`/api/health sem 200`**: 503 é o banco ou o cache fora (log do `app` no CloudWatch); 502 e 504, o PHP-FPM; 404, um código sem o `/api/health` (anterior à `feat/api-qualidade`).
- **`AccessDenied` no `StartInstanceRefresh` pedindo `iam:PassRole` ou `ec2:RunInstances`**: o Auto Scaling passou a usar `$Latest` ou `$Default` (o `compute.tf` usa a versão exata). Volte para a versão exata. Se a AWS pedir mesmo assim, acrescente a permissão no `local.deploy_role_policy`, presa à role das instâncias (`iam:PassRole` com `iam:PassedToService = ec2.amazonaws.com`) e ao launch template, e atualize o `tests/cicd.tftest.hcl` junto.
- **O deploy não começou depois do push da tag**: a tag não começa com `v`, mais de três tags foram no mesmo push, ou o workflow está desligado em Actions.
- **CI: `composer audit` vermelho**: o log mostra o pacote, o alerta e as versões atingidas. Atualize o pacote num pull request (`composer update <pacote>`).
- **CI: `Vite manifest not found` no job `PHP`**: um teste abre tela sem `withoutVite()` (regra do `AGENTS.md`).
- **CI ou deploy: o Trivy acusa CRITICAL numa imagem**: a correção já existe. Atualize a imagem base no `docker/php/Dockerfile` (o pull request do Dependabot `docker` costuma trazer) e rode o build de novo. Se o Debian ou o Alpine já corrigiu e a imagem oficial ainda não saiu, atualize só o pacote no estágio da imagem, num pull request. No deploy, nada foi publicado e a `image_tag` continua a anterior: depois do merge da correção, crie uma tag `v*` nova.
- **CI: o job `MySQL` ficou verde sem rodar teste**: o commit não tem o `phpunit.mysql.xml` (o aviso "Testes no MySQL pulados" aparece na rodada). Normal antes da `feat/agendamentos`.
- **CI: `no file exists at "./../docker-compose.prod.yml"`** no job `Terraform`: o checkout não trouxe o repositório inteiro (um `sparse-checkout`, por exemplo). O `compute.tf` precisa do compose da raiz.
