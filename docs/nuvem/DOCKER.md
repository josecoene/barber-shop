# Docker do BarberFlow

Este guia explica como rodar o BarberFlow em containers: o ambiente de desenvolvimento, a homologação local (as mesmas imagens que vão para a AWS) e o que a produção na EC2 usa.

Tudo sai de um Dockerfile só, `docker/php/Dockerfile`, dividido em estágios. Cada compose escolhe o estágio (o "alvo") de que precisa.

## Arquivos

| Arquivo | Para que serve |
|---|---|
| `docker/php/Dockerfile` | Imagem em estágios: `base`, `dev`, `vendor`, `assets`, `web` e `app` |
| `docker/php/entrypoint.sh` | Roda antes do PHP-FPM na produção e na homolog (pastas em memória, `optimize` e, se pedido, `migrate`) |
| `docker/php/entrypoint-dev.sh` | Roda antes do PHP-FPM no dev (`.env`, `composer install`, chave e `migrate`) |
| `docker/php/healthcheck.sh` | Healthcheck do PHP-FPM (vira `php-fpm-healthcheck` na imagem) |
| `docker/php/conf.d/*.ini` | Ajustes do PHP: `barberflow.ini`, `otimizado.ini` e `dev.ini` |
| `docker/php/php-fpm.d/zz-barberflow.conf` | Tamanho do pool do PHP-FPM e o `/ping` do healthcheck |
| `docker/nginx/default.conf` | Configuração do Nginx (dev e imagem `web`) |
| `docker-compose.yml` | Desenvolvimento, projeto `barberflow-dev` |
| `docker-compose.prod.yml` | Produção na EC2: `app`, `web` e `scheduler` |
| `docker-compose.homolog.yml` | Homologação local, sempre junto com o `docker-compose.prod.yml` |
| `docker/homolog/homolog.env.example` | Modelo do `docker/homolog/homolog.env`, que guarda a chave e as senhas da homolog local (o Git e o build ignoram o arquivo de verdade) |
| `docker/mysql/init/01-exporter-user.sh` | Cria o usuário `exporter` do monitoramento quando o banco da homolog nasce |
| `docker-compose.monitoring.yml` | Monitoramento da homolog: Prometheus, Grafana, Loki e Alloy (`docs/nuvem/MONITORAMENTO.md`) |
| `docker-compose.carga.yml` | Homolog de carga, com os limites levantados e o k6 (`load-tests/README.md`) |
| `.dockerignore` | O que não entra no build (vendor, node_modules, `.env`, o `homolog.env`, caches do dev, testes) |

## Antes de começar

- Docker Desktop com Compose 2.24.4 ou mais novo (`docker compose version`). Os arquivos usam o `!override` do Compose e o `start_interval` dos healthchecks, que pede o Docker Engine 25 ou mais novo. Testado no Windows 11 com Docker 29.5.3 e Compose v5.1.4.
- Internet no primeiro build: as imagens e os pacotes do Composer e do npm. Desde a `feat/auth`, o `vite.config.js` não baixa mais nenhuma fonte.
- Não precisa de PHP, Composer, MySQL nem Node no computador.
- Dev e homolog usam a mesma porta (8080). Suba um de cada vez.

## Portas

| Porta | Onde | Serviço | Quem acessa |
|---|---|---|---|
| 8080 | dev e homolog | `web` (Nginx) | Só este computador (127.0.0.1) |
| 3306 | dev | `db` (MySQL) | Só este computador, para DBeaver ou MySQL Workbench |
| 8025 | homolog | `mailpit` (tela dos e-mails) | Só este computador |
| 3000 e 9090 | homolog com monitoramento | Grafana e Prometheus (`docs/nuvem/MONITORAMENTO.md`) | Só este computador |
| 80 | produção | `web` (Nginx) | O ALB da AWS |
| 9000, 6379, 1025 e 8081 | todos | PHP-FPM, Redis, SMTP do Mailpit e métricas do Nginx | Só a rede interna do Docker |
| 3100, 9104 e 9113 | homolog com monitoramento | Loki e os exporters do MySQL e do Nginx | Só a rede interna do Docker |

## Desenvolvimento (projeto `barberflow-dev`)

O código fica no seu computador e entra no container montado em `/var/www/html`. Editou um arquivo PHP ou Blade, é só recarregar a página.

### Subir

Na raiz do repositório:

```
docker compose run --rm assets
docker compose up -d --wait
```

1. O primeiro comando gera o CSS e o JS do Vite em `public/build`, num container com Node 24. Só precisa rodar de novo quando mudar alguma coisa em `resources/css` ou `resources/js`. Com Node 24 instalado no computador, `npm install` e `npm run build` fazem o mesmo.
2. O segundo sobe banco, Redis, app, agendador e Nginx e espera todos ficarem saudáveis.

Na primeira subida o container `app` cria o `.env` a partir do `.env.example`, instala as dependências do Composer, gera a `APP_KEY` e roda as migrations. Isso leva alguns minutos; nas próximas vezes leva segundos, porque cada passo só roda quando falta alguma coisa. O `composer install` também roda de novo quando o `composer.lock` mudou desde a última instalação (por exemplo, depois de um `git pull` que trouxe pacote novo). O entrypoint do dev nunca roda `migrate:fresh` nem seed.

Depois abra http://localhost:8080/login.

### Comandos do dia a dia

| O que fazer | Comando |
|---|---|
| Ver o estado dos containers | `docker compose ps` |
| Acompanhar os logs | `docker compose logs -f app` (ou `web`, `scheduler`, `db`) |
| Rodar uma migration nova | `docker compose exec app php artisan migrate` |
| Atualizar o `vendor` depois de um `git pull` que mudou o `composer.lock` | `docker compose exec app composer install` |
| Popular o banco (usuário de teste e serviços) | `docker compose exec app php artisan db:seed` |
| Abrir o Tinker | `docker compose exec app php artisan tinker` |
| Listar as rotas | `docker compose exec app php artisan route:list` |
| Rodar os testes | `docker compose exec app composer test` |
| Conferir a formatação | `docker compose exec app vendor/bin/pint --test` |
| Recarregar o Nginx depois de editar `docker/nginx/default.conf` | `docker compose exec web nginx -s reload` |
| Parar tudo e manter os dados | `docker compose down` |

Os testes usam SQLite em memória, porque o `phpunit.xml` força `DB_CONNECTION=sqlite` mesmo dentro do container. Eles não mexem no MySQL do dev.

O `docker compose down -v` apaga também os volumes: banco, Redis, `vendor` e `node_modules`. Use só para começar do zero.

### Como o dev funciona

- As variáveis do bloco `environment` do `docker-compose.yml` valem mais que o `.env`. Por isso os `DB_*` do `.env` não importam dentro do container: o app sempre usa o MySQL do serviço `db`.
- `CACHE_STORE` e `SESSION_DRIVER` ficam fora do compose de propósito. O `phpunit.xml` não força esses dois, e os testes passariam a usar o Redis. Para testar o Redis no dev, mude os dois no `.env` para `redis`.
- O `vendor` fica num volume do Docker, e não na pasta do Windows, porque assim o Laravel responde bem mais rápido.
- O `scheduler` roda `php artisan schedule:work` com a mesma imagem e o mesmo código do `app`.
- Para usar o `npm run dev` do computador (Vite com recarga automática), o Laravel do container lê o `public/hot` e o navegador busca os arquivos direto no Vite. Esse caminho não foi testado nesta branch; o testado é o `docker compose run --rm assets`.
- Para abrir o banco no DBeaver ou no MySQL Workbench, use `127.0.0.1`, porta 3306, com as credenciais do `docker-compose.yml` (bloco `x-mysql-credentials`).
- Só o Windows 11 foi testado. No Linux, os arquivos que o container cria na pasta do projeto ficam com dono root; se o Laravel reclamar de permissão em `storage` ou `bootstrap/cache`, rode `chmod -R a+rwX storage bootstrap/cache`.

## Homologação local (projeto `barberflow-homolog`)

A homolog sobe as imagens de produção (alvos `app` e `web`) construídas no seu computador, com `APP_ENV=production`, disco só de leitura, cache e sessão no Redis, MySQL 8.4 no lugar do RDS e o Mailpit no lugar do e-mail de verdade. É o ensaio do que vai para a EC2.

O ambiente do Laravel parte do `.env.example` (todas as chaves do projeto, com valor de exemplo) e recebe por cima o bloco de produção do `docker-compose.prod.yml` e as linhas da homolog: `APP_URL=http://localhost:8080`, `RUN_MIGRATIONS=true`, e-mail pelo Mailpit e `DEMO_SEED_ENABLED=true`.

### A chave e as senhas da homolog (`docker/homolog/homolog.env`)

A homolog usa quatro segredos, que nascem aleatórios e ficam no `docker/homolog/homolog.env`, só neste computador:

| Variável | Para que serve |
|---|---|
| `HOMOLOG_APP_KEY` | Chave do Laravel (`APP_KEY`) |
| `HOMOLOG_DB_PASSWORD` | Senha do usuário `barberflow` do MySQL, que o app usa |
| `HOMOLOG_DB_EXPORTER_PASSWORD` | Senha do usuário `exporter` do MySQL, só de leitura, do monitoramento |
| `HOMOLOG_GRAFANA_ADMIN_PASSWORD` | Senha do admin do Grafana do monitoramento |

Três linhas opcionais entram depois, para o admin e os logins de demonstração: `HOMOLOG_ADMIN_EMAIL`, `HOMOLOG_ADMIN_PASSWORD` e `HOMOLOG_DEMO_USER_PASSWORD` (seção "Dados de demonstração e administrador"). Nenhum compose lê essas três.

- **O arquivo é gerado uma vez só e guardado enquanto o banco da homolog existir.** O MySQL grava as senhas do app e do exporter só na primeira subida, quando cria o banco: com outra senha, o app não entra mais no banco (veja "Problemas comuns"). Com outra chave, as sessões abertas deixam de valer.
- O Git ignora o arquivo (`docker/homolog/.gitignore`), e o build das imagens também (`.dockerignore`). O modelo sem valores é o `docker/homolog/homolog.env.example`.
- Os compose leem o arquivo pelo `--env-file`, que as funções `homolog` (abaixo) e `homologmon` (`docs/nuvem/MONITORAMENTO.md`) já passam.
- Uma variável `HOMOLOG_*` definida na janela do terminal vale mais que o arquivo. Se você usou o jeito antigo (as variáveis no terminal, ou no `.env` da raiz), abra um terminal novo ou limpe as variáveis: `Remove-Item Env:HOMOLOG_*` no PowerShell, `unset HOMOLOG_APP_KEY HOMOLOG_DB_PASSWORD` no Git Bash.
- Os valores servem só para este computador: nunca vão para a AWS, que tem a própria chave e a própria senha no SSM (`docs/nuvem/TERRAFORM.md`).

### Subir

No PowerShell, na raiz do repositório:

```powershell
docker compose down
docker build -f docker/php/Dockerfile --target app -t barberflow-app:homolog .
docker run --rm --entrypoint php barberflow-app:homolog -r "echo 'HOMOLOG_APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL.'HOMOLOG_DB_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'HOMOLOG_DB_EXPORTER_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'HOMOLOG_GRAFANA_ADMIN_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL;" | Out-File -NoClobber -Encoding ascii docker\homolog\homolog.env
function homolog { docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog @args }
homolog up -d --build --wait
```

1. Derruba o dev, se estiver no ar (os dois usam a porta 8080).
2. Faz o build da imagem `app`, que é onde o `php` mora.
3. **Só na primeira vez:** gera o `docker/homolog/homolog.env`. O `php` da imagem escreve as quatro linhas com valores aleatórios (a chave tem o mesmo formato do `php artisan key:generate --show`), e o `Out-File -NoClobber` grava o arquivo e **se recusa a sobrescrever um que já existe** (`The file ... already exists`): é isso que guarda as senhas da primeira subida. Nada aparece na tela. O `-Encoding ascii` evita o formato UTF-16 que o Windows PowerShell 5.1 usaria e que o compose não lê.
4. Cria a função `homolog`, que junta o `--env-file`, os dois arquivos e o nome do projeto. Ela vale só nessa janela do terminal: numa janela nova, crie de novo (o passo 3 não se repete).
5. Sobe a stack: o `app` roda o `php artisan optimize` e as migrations (`migrate --force --isolated`). Nunca roda seed.

No Git Bash, os passos 3 e 4 ficam assim (o `noclobber` faz a mesma recusa do `-NoClobber`):

```bash
(set -o noclobber; docker run --rm --entrypoint php barberflow-app:homolog -r "echo 'HOMOLOG_APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL.'HOMOLOG_DB_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'HOMOLOG_DB_EXPORTER_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'HOMOLOG_GRAFANA_ADMIN_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL;" > docker/homolog/homolog.env)
homolog() { MSYS_NO_PATHCONV=1 docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog "$@"; }
```

O `MSYS_NO_PATHCONV=1` impede o Git Bash de trocar os caminhos que vão para dentro do container (como o `HOME=/tmp` do Tinker, abaixo) por caminhos do Windows, como na função `tf` do `docs/nuvem/TERRAFORM.md`. Os caminhos do `--env-file` e do `-f` são relativos e não mudam.

Depois abra http://localhost:8080/login. Os e-mails que a aplicação mandar aparecem em http://localhost:8025. Com o monitoramento (Prometheus, Grafana e Loki), use a função `homologmon` do `docs/nuvem/MONITORAMENTO.md` no lugar da `homolog`.

Nas próximas vezes, só os passos 1, 2 (se o código mudou), 4 e 5. Se o passo 3 falhou no meio e deixou um `homolog.env` vazio ou pela metade, e o banco da homolog ainda não existe, apague o arquivo e repita o passo 3.

**Vindo do jeito antigo**, com a `HOMOLOG_APP_KEY` e a `HOMOLOG_DB_PASSWORD` guardadas no `.env` da raiz: passe as duas linhas para o `docker/homolog/homolog.env`, tire as duas do `.env` da raiz e acrescente as do monitoramento:

```powershell
docker run --rm --entrypoint php barberflow-app:homolog -r "echo PHP_EOL.'HOMOLOG_DB_EXPORTER_PASSWORD='.bin2hex(random_bytes(24)).PHP_EOL.'HOMOLOG_GRAFANA_ADMIN_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL;" | Out-File -Append -Encoding ascii docker\homolog\homolog.env
```

- O `PHP_EOL` do começo põe uma quebra de linha antes das linhas novas. Um arquivo escrito à mão costuma terminar sem ela (o padrão do VS Code), e o `-Append` grudaria a primeira linha nova no fim da `HOMOLOG_DB_PASSWORD`: a senha do banco mudaria e a do exporter sumiria. Se o arquivo já terminava com a quebra, sobra uma linha vazia, que o compose ignora.
- Se só uma das duas falta, tire do comando a parte da que já está no arquivo. Com o mesmo nome duas vezes, o compose usa a última linha, e a senha da primeira deixa de valer.

O banco criado pelo jeito antigo não tem o usuário `exporter`: crie com o roteiro "Banco que já existe" do `docs/nuvem/MONITORAMENTO.md`.

### Comandos

Todos com a função `homolog` (passo 4). O compose lê os arquivos inteiros até para derrubar, então o `homolog.env` precisa estar no lugar.

| O que fazer | Comando |
|---|---|
| Ver o estado | `homolog ps` |
| Logs do app | `homolog logs -f app` |
| Logs do agendador (a rodada do lembrete a cada 15 minutos) | `homolog logs -f scheduler` |
| Situação das migrations | `homolog exec app php artisan migrate:status` |
| Abrir o Tinker | `homolog exec -e HOME=/tmp app php artisan tinker` |
| Parar e manter os dados | `homolog down` |

O Tinker precisa do `HOME=/tmp` porque o disco do container é só de leitura e ele grava a própria configuração na pasta do usuário.

### Dados de demonstração e administrador

A homolog sobe com o banco vazio. Para entrar nas telas, ela precisa de um admin (`AdminUserSeeder`) e, para a demonstração, dos dados do `DemoSeeder`: 3 barbeiros com login, 15 clientes (4 com conta), os serviços e 30 dias de agenda com pagamentos. Os dois seeders recebem a senha só no ambiente do comando, num container descartável, e nunca de um arquivo versionado.

**1. As senhas, uma vez só.** O e-mail do admin e as duas senhas ficam no mesmo `docker/homolog/homolog.env`, em três linhas a mais. Com a imagem `app` já construída (passo 2 de "Subir"):

```powershell
docker run --rm --entrypoint php barberflow-app:homolog -r "echo PHP_EOL.'HOMOLOG_ADMIN_EMAIL=admin@demo.barberflow.test'.PHP_EOL.'HOMOLOG_ADMIN_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL.'HOMOLOG_DEMO_USER_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL;" | Out-File -Append -Encoding ascii docker\homolog\homolog.env
```

As senhas saem aleatórias, com 32 letras e números, e nada aparece na tela. O `PHP_EOL` do começo evita grudar a primeira linha nova na última do arquivo (veja "Vindo do jeito antigo"). Rode uma vez só: com a mesma linha duas vezes, o compose e a função abaixo usam a última. Nenhum compose lê essas três linhas, então elas nunca chegam aos containers que ficam no ar; só entram, com `-e`, nos containers descartáveis dos seeders e do k6.

**2. Os seeders.** Com a homolog no ar e a mesma função que subiu a stack (`homolog`, ou `homologmon` com o monitoramento):

```powershell
function Get-HomologValue([string] $Name) { $l = Get-Content docker\homolog\homolog.env | Where-Object { $_ -like "$Name=*" } | Select-Object -Last 1; if (-not $l) { throw "$Name não está no homolog.env" }; $l.Substring($Name.Length + 1) }

$env:DEMO_USER_PASSWORD = Get-HomologValue 'HOMOLOG_DEMO_USER_PASSWORD'
homolog run --rm --no-deps -e DEMO_USER_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=DemoSeeder --force
Remove-Item Env:DEMO_USER_PASSWORD

$env:ADMIN_NAME = 'Administrador'
$env:ADMIN_EMAIL = Get-HomologValue 'HOMOLOG_ADMIN_EMAIL'
$env:ADMIN_PASSWORD = Get-HomologValue 'HOMOLOG_ADMIN_PASSWORD'
homolog run --rm --no-deps -e ADMIN_NAME -e ADMIN_EMAIL -e ADMIN_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=AdminUserSeeder --force
Remove-Item Env:ADMIN_NAME, Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
```

- A função `Get-HomologValue` lê uma linha do `homolog.env` sem mostrar o valor. O `-e` sem valor repassa a variável do terminal para o container, e o `Remove-Item` tira a senha do terminal logo depois.
- O `DemoSeeder` responde `Agenda de demonstração criada: N agendamentos e M pagamentos.` (em 15/11/2026: 177 e 95). Rodar de novo não duplica nada: `A agenda de demonstração já existe: nenhum agendamento novo foi criado.`. Ele também não muda o que já existe: um serviço com o preço, a duração ou o status que o admin mudou no ensaio fica assim, um serviço excluído continua excluído (dos 4 de exemplo, ele cria só os que nunca existiram) e nenhuma senha muda. O `AdminUserSeeder` responde `Administrador inicial criado.` e nunca muda um usuário que já existe.
- Sem a `DEMO_USER_PASSWORD`, ou com menos de 8 caracteres, o `DemoSeeder` avisa e não cria nada. Fora da homolog ele também não roda, porque só a homolog liga o `DEMO_SEED_ENABLED`. O `AdminUserSeeder` segue a mesma regra: sem a `ADMIN_PASSWORD`, ou com menos de 8 caracteres, ele avisa e não cria o admin.
- **A agenda segue o dia em que o seeder rodou:** os 30 dias ficam em volta daquela data (15 antes, o próprio dia e 14 depois). Como ele não duplica, um banco que já tem a demonstração continua com a agenda do dia em que ela nasceu. Uma agenda em volta de outra data pede um banco novo, o que apaga os dados da homolog.
- No Git Bash, os mesmos passos com `export` e `unset`, como no `load-tests/README.md`.

Logins que os dois seeders criam (todos com o e-mail já confirmado):

| Perfil | E-mail | Senha |
|---|---|---|
| Admin | o `HOMOLOG_ADMIN_EMAIL` (`admin@demo.barberflow.test`) | `HOMOLOG_ADMIN_PASSWORD` |
| Barbeiro | `carlos.mendes`, `rafael.souza` e `diego.almeida`, todos `@demo.barberflow.test` | `HOMOLOG_DEMO_USER_PASSWORD` |
| Cliente com conta | `ana.ribeiro` (a que tem o prêmio de fidelidade), `camila.fernandes`, `felipe.gomes` e `joao.nunes`, todos `@demo.barberflow.test` | `HOMOLOG_DEMO_USER_PASSWORD` |

Para entrar pela tela sem mostrar a senha, copie para a área de transferência e cole no campo: `Get-HomologValue 'HOMOLOG_DEMO_USER_PASSWORD' | Set-Clipboard`. Limpe depois com `Set-Clipboard -Value ' '` (o histórico da área de transferência do Windows, o Win+V, também guarda o que foi copiado). O domínio `demo.barberflow.test` é reservado para teste: nenhum e-mail chega a uma pessoa, e o Mailpit guarda tudo.

**Trocar a senha de demonstração.** O `DemoSeeder` não muda a senha de um login que já existe (`database/seeders/DemoSeeder.php:636`): a senha nova pede um banco novo, e isso apaga os dados da homolog. Combine com o grupo antes. No PowerShell, na raiz do repositório, com a mesma função que subiu a stack (`homolog`, ou `homologmon` com o monitoramento):

```powershell
homolog down
$linha = docker run --rm --entrypoint php barberflow-app:homolog -r "echo 'HOMOLOG_DEMO_USER_PASSWORD='.bin2hex(random_bytes(16));"
if ([regex]::IsMatch($linha, '^HOMOLOG_DEMO_USER_PASSWORD=[0-9a-f]{32}$')) { (Get-Content docker\homolog\homolog.env) | ForEach-Object { if ($_ -like 'HOMOLOG_DEMO_USER_PASSWORD=*') { $linha } else { $_ } } | Set-Content -Encoding ascii docker\homolog\homolog.env }
if ((Get-Content docker\homolog\homolog.env) -ccontains $linha) { 'Senha trocada no homolog.env' } else { 'A troca falhou: não apague o banco' }
Remove-Variable linha
```

O `down` sem `-v` mantém os volumes. O `docker run` gera a linha nova como no passo 1 desta seção (16 bytes aleatórios, 32 letras e números), e o primeiro `if` troca só a linha `HOMOLOG_DEMO_USER_PASSWORD` do `homolog.env`, com o mesmo `-Encoding ascii`: as outras linhas ficam como estão, e a senha não aparece na tela. O `[regex]::IsMatch` confere a linha nova sem guardá-la no `$Matches`, como o `-match` faria, e o `Remove-Variable` tira a senha nova do terminal. O segundo `if` lê o arquivo de novo e só mostra `Senha trocada no homolog.env` quando acha nele a linha nova. Se o `docker run` não devolver só a linha nova, se o `homolog.env` estiver só de leitura ou sem a linha `HOMOLOG_DEMO_USER_PASSWORD`, ou se o terminal estiver fora da raiz do repositório, sai `A troca falhou: não apague o banco`, e o arquivo fica como estava. O comando foi conferido em 16/11/2026 num `homolog.env` falso, no PowerShell 7 e no Windows PowerShell 5.1, rodado de uma vez e linha por linha: na troca, só essa linha mudou, sem BOM e com o fim de linha CRLF de antes, e o `$Matches` ficou sem a senha; nos cinco casos de falha (o `docker run` sem saída ou com duas linhas, o arquivo só de leitura, o arquivo sem a linha e o terminal fora da raiz), saiu `A troca falhou`, e o arquivo ficou igual. Só com `Senha trocada no homolog.env` na tela, apague o banco e suba de novo:

```powershell
docker volume rm barberflow-homolog_dbdata
homolog up -d --wait
```

O banco nasce vazio, com as mesmas senhas do app e do `exporter`, que continuam no `homolog.env`, e o `app` roda as migrations. Depois, rode os dois seeders do passo 2 desta seção: o `DemoSeeder` cria os logins com a senha nova e a agenda em volta do dia em que roda, e o `AdminUserSeeder`, o admin com a senha de antes. Foi o caminho de 15/11/2026, no computador do Leonam, depois que a senha de demonstração apareceu na saída de uma busca (`docs/RELATORIO-TECNICO.md`, seção 4.12).

### O que conferir

```
curl.exe -I http://localhost:8080/up
curl.exe -s -D - -o NUL -H "Accept-Encoding: gzip" http://localhost:8080/login
```

- `/login` e `/up` respondem 200.
- Nenhuma resposta traz `X-Powered-By`, e o header `Server` mostra só `nginx`, sem versão.
- Os arquivos de `/build/assets/` que o HTML do `/login` cita voltam com `Content-Encoding: gzip`, `Cache-Control: max-age=31536000`, `Cache-Control: public, immutable` e `X-Content-Type-Options: nosniff`.
- No log do `web`, as linhas de acesso trazem o caminho sem a query string.

## Produção na EC2

O `docker-compose.prod.yml` tem só `app`, `web` e `scheduler`. O banco é o RDS e o cache é o ElastiCache, ambos fora do compose.

- O `user_data` da instância (`terraform/templates/user_data.sh.tftpl`, branch `feat/infra-computacao`) grava em `/opt/barberflow` este compose e o `.env` ao lado dele, montado com os parâmetros do SSM, com `APP_IMAGE` e `WEB_IMAGE` apontando para o ECR, e roda `docker compose -f docker-compose.prod.yml up -d`. O passo a passo e o formato do `.env` estão no `docs/nuvem/TERRAFORM.md`, seção "Launch template e user_data".
- Os logs vão para o CloudWatch pelo driver `awslogs`. O padrão fica no `daemon.json` do Docker da EC2, no log group `/barberflow/<ambiente>/app`, e vale para o `app` e o `scheduler`. O `web` tem um `logging:` próprio neste compose, para escrever no grupo `/barberflow/<ambiente>/web`: driver `awslogs`, com a região, o grupo e o ID da instância vindos do `.env` que o `user_data` monta (`AWSLOGS_REGION`, `AWSLOGS_WEB_GROUP` e `INSTANCE_ID`), e as mesmas opções do `daemon.json` (sem criar grupo, stream `<ID da instância>/<nome do container>` e modo `non-blocking`). Os log groups nascem no `terraform/logs.tf`. Como ler: `docs/nuvem/TERRAFORM.md`, seção "Logs no CloudWatch".
- A rodada do lembrete por e-mail roda dentro do processo do `scheduler` (`routes/console.php`), e o log dela sai na saída dele: o aviso de cada lembrete que falhou, só com o id do agendamento, e uma linha `ERROR` só com as contas da rodada. Na AWS, as duas vão para o grupo `app`, no stream do `scheduler`; na homolog, para o `homolog logs scheduler` e, com o monitoramento, para o Loki. A linha da rodada sai `DONE` mesmo quando ela falha (um detalhe do Laravel, igual na 13.33 e na 13.40): procure o `ERROR`.
- Os limites de tempo do banco, do cache e do servidor de e-mail (`DB_CONNECT_TIMEOUT`, `DB_READ_TIMEOUT`, `REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT` e `MAIL_TIMEOUT`) não são parâmetros do SSM: na AWS valem os padrões do `config/database.php` e do `config/mail.php` (3, 5, 3, 3 e 10 segundos). Na homolog, eles vêm do `.env.example`, com os mesmos valores.
- Na homolog, o `docker-compose.homolog.yml` troca o `logging:` do `web` pelo driver padrão do Docker (`logging: !override` com `driver: json-file`). Assim ela sobe no Docker Desktop sem credencial da AWS, e o `docker compose logs web` funciona como antes. O `docker compose config` da homolog não tem nenhum `awslogs`.
- O bloco de produção (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`) vale mais que o `.env`. Cache e sessão ficam no Redis porque as travas do `migrate --isolated` e do `onOneServer()` precisam de um cache que todas as instâncias enxerguem.
- `SESSION_SECURE_COOKIE` é `true` por padrão. Sem HTTPS no ALB, defina `SESSION_SECURE_COOKIE=false`, senão o login não segura a sessão. Na AWS, o parâmetro do SSM segue o certificado do ALB: `true` com `certificate_arn`, `false` sem ele.
- Com `RUN_MIGRATIONS=true` no `.env`, o `app` roda `php artisan migrate --force --isolated` ao subir. Com várias instâncias subindo juntas, só uma migra. O `scheduler` nunca migra.
- Cada instância roda o seu `scheduler`; o `onOneServer()` garante que cada tarefa rode uma vez só.
- O disco dos containers é só de leitura. O Laravel escreve só em pastas em memória (`bootstrap/cache`, `storage/framework`, `storage/logs` e `/tmp`), que começam vazias em cada container novo. O entrypoint recria as subpastas do `storage/framework` antes do `optimize`.
- Modo de manutenção: `docker compose -f docker-compose.prod.yml exec app php artisan down` (e `php artisan up` para voltar). Com o driver padrão (`file`), o aviso fica na memória daquele container: vale só para a instância em que o comando rodou e some quando o container é recriado. Para valer em todas as instâncias, ponha `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` no `.env` antes de subir os containers (a config fica congelada na subida); na AWS, os parâmetros do SSM já trazem os dois. Aí o `down` e o `up` rodados numa instância valem para todas, e cada requisição passa a consultar o Redis. O `/up` fica fora da manutenção e continua respondendo 200 para o ALB.
- O bundle de CA do RDS fica em `/etc/ssl/certs/rds-global-bundle.pem`. Com `MYSQL_ATTR_SSL_CA` apontando para ele (parâmetro `/barberflow/<ambiente>/MYSQL_ATTR_SSL_CA`, criado pelo `terraform/ssm.tf`), o Laravel conecta no banco por TLS, e o RDS recusa conexão sem TLS. A lista dos parâmetros está no `docs/nuvem/TERRAFORM.md`, seção "Parâmetros do SSM".
- O entrypoint de produção para com uma mensagem clara se a `APP_KEY` vier vazia ou não tiver os 32 bytes que o Laravel exige (o valor provisório do parâmetro `APP_KEY` no SSM, por exemplo). Sem essa conferência, o container subiria, o `/up` responderia 200 e toda tela daria 500. Ele nunca gera chave, nunca roda seed e nunca muda dono de arquivo.
- Healthchecks: o `app` pergunta `/ping` ao PHP-FPM, o `web` lê a página de métricas do Nginx e o `scheduler` roda `php artisan schedule:list`. O ALB olha o `/up` do Laravel.

## A imagem por dentro

| Estágio | O que faz |
|---|---|
| `composer` | Só fornece o binário do Composer 2.11.2 |
| `base` | PHP-FPM 8.4.27 com `pdo_mysql`, `redis` e OPcache (já vem ligado na imagem oficial; o build confere), sem as ferramentas de compilação, `php.ini-production`, os `.ini` do BarberFlow, o bundle de CA do RDS, o pool e o healthcheck |
| `dev` | `base` com Composer e `unzip`; o OPcache volta a conferir a data dos arquivos |
| `vendor` | `composer install --no-dev --no-scripts` e `composer dump-autoload --optimize --no-dev` |
| `assets` | `npm ci` e `npm run build` no Node 24 (gera o `public/build`) |
| `web` | Nginx com o `default.conf`, o `public/` e o `public/build` |
| `app` | `base` com o código, o `vendor` e o `public/build`, tudo do usuário `www-data`; roda `php artisan package:discover` no build e sobe como `www-data` |

O CI (job `Docker` do `.github/workflows/ci.yml`) faz o build dos alvos `app` e `web` em todo pull request e passa o Trivy nas duas imagens. O deploy faz o build de novo, a partir do commit que vai para a AWS, passa o mesmo Trivy e só então manda as duas para o ECR (`docs/nuvem/CI-CD.md`). Os dois usam o BuildKit fixado pela versão e pelo digest.

### Versões fixas

| O quê | Versão |
|---|---|
| PHP | `php:8.4.27-fpm-bookworm` |
| Composer | `composer:2.11.2` |
| Node | `node:24.22.3-alpine` |
| Nginx | `nginx:1.30.6-alpine` |
| MySQL (dev e homolog) | `mysql:8.4.13` |
| Redis (dev e homolog) | `redis:8.10.3-alpine` |
| Mailpit (homolog) | `axllent/mailpit:v1.33.1` |
| Extensão redis do PECL | 6.3.0, conferida por SHA-256 |
| Bundle de CA do RDS | conferido por SHA-256 |

O Dependabot propõe toda segunda as versões novas do Dockerfile e dos compose, 7 dias depois de publicadas (`docs/nuvem/CI-CD.md`, "Dependabot"). Para atualizar à mão, troque a versão no arquivo e rode o build de novo. A extensão redis e o bundle do RDS também têm o SHA-256 no Dockerfile (`PHPREDIS_SHA256` e `RDS_CA_BUNDLE_SHA256`). Quando a AWS publicar um bundle novo, o build para na conferência: baixe o arquivo, confira de onde veio e calcule o hash novo (`Get-FileHash -Algorithm SHA256 arquivo` no PowerShell, `sha256sum arquivo` no Git Bash).

### PHP e PHP-FPM

- `expose_php = Off`: some o header `X-Powered-By`.
- OPcache ligado com os ajustes da rodada otimizada da Sprint 4 (`otimizado.ini`): 128 MB, até 20 mil arquivos e cache de caminhos. Na produção ele não confere a data dos arquivos (`validate_timestamps=0`), porque o código só muda com imagem nova. No dev confere em toda requisição.
- Pool `www` em `pm = dynamic`, com até 10 processos (cada um usa por volta de 30 a 50 MB) e reciclagem a cada 500 requisições. O `docker.conf` oficial continua valendo: logs no stderr e `clear_env = no`, para o Laravel enxergar as variáveis do container. Com o banco congelado, cada processo fica ocupado até o limite de leitura do MySQL desistir (cerca de 20 s, o `DB_READ_TIMEOUT` aplicado pelo `AppServiceProvider`), e 10 pedidos assim enchem o pool (`load-tests/resiliencia/README.md`, "Com o limite de leitura do MySQL"). Com o servidor de e-mail congelado, o processo que manda um e-mail fica ocupado até o `MAIL_TIMEOUT` desistir (10 s, ou até 20 s se ele congelar no meio da conversa), e não mais os 60 s do `default_socket_timeout` do PHP (`load-tests/resiliencia/README.md`, "Com o limite de tempo do servidor de e-mail").
- Na imagem `app` o PHP-FPM roda como `www-data`, então ele avisa no log que ignora as linhas `user` e `group` do pool. É esperado.

### Extensões do PHP

- Além do que a imagem oficial já traz (`mbstring`, `curl`, `openssl`, `pdo_sqlite`, `sodium` e outras), o build compila só o `pdo_mysql` e o `redis` e confere que o OPcache está ligado.
- **Sem a `intl`.** O Laravel 13 não exige a extensão: nenhum pacote do `composer.lock` pede `ext-intl`, e os que sugerem usam os polyfills `symfony/polyfill-intl-*`, que já estão no vendor. Ela só faria falta no `Number::` do Laravel (`Number::format`, `Number::currency` e parecidos) e nas validações de e-mail `email:dns` e `email:spoof`, que a aplicação não usa (conferido em 14/11/2026 no código das branches da aplicação, até a `feat/pagamentos-dashboard`). O CI roda os testes com a intl desligada (`docs/nuvem/CI-CD.md`), e um uso novo falha no pull request, e não na AWS. Se a aplicação passar a precisar dela, a extensão entra no estágio `base`, no mesmo `RUN` das outras, e o `:intl` sai do `ci.yml`.
- **Sem ferramentas de compilação.** A imagem oficial do PHP (Debian) deixa instaladas as ferramentas que compilam extensões (`PHPIZE_DEPS`: `gcc`, `g++`, `make`, `autoconf` e outras). O estágio `base` tira tudo no fim do mesmo `RUN` que compila o `pdo_mysql` e o `redis`, e o build para se `gcc`, `make` ou os cabeçalhos do sistema sobrarem. A imagem `app` passou de 175 para 117 pacotes, e o Trivy, de 311 alertas altos e críticos (4 críticos) para 115 (3 críticos), em 14/11/2026. Nenhum deles tinha correção publicada nessa data, então o Trivy do CI (`--ignore-unfixed`, CRITICAL) passa. Em 15/11/2026, na imagem refeita com o Laravel 13.40.0 e o banco do Trivy do dia, eram 119 (3 críticos), ainda nenhum com correção publicada, e nenhum no vendor do Composer. E quem invadir o container não acha um compilador pronto.
- Na imagem `web`, o Trivy de 15/11/2026 achou 3 alertas altos com correção publicada, todos no `libxslt` 1.1.43-r3 do `nginx:1.30.6-alpine` (corrigidos na 1.1.43-r4), e nenhum crítico: o Trivy do CI passa, porque só barra CRITICAL. Eles saem com um `nginx` novo, que o Dependabot propõe quando sair, ou com um `apk upgrade` do pacote no estágio `web`, decisão que ficou em aberto (`docs/RELATORIO-TECNICO.md`, seção 8).
- O tamanho da imagem não muda: os arquivos tirados ficam na camada da imagem oficial, e a camada nova só os esconde. O que conta é o sistema de arquivos final, que é o que o Trivy e o container enxergam.
- Para compilar outra extensão, instale as ferramentas no mesmo `RUN` dela (`apt-get install $PHPIZE_DEPS`) e tire no fim, como o estágio `base` faz. O `dev` herda o `base` e também não tem compilador.

### Nginx

- Log com o mesmo começo da Sprint 4 (o painel do Grafana lê o `$request_time` nessa posição), mais o `X-Forwarded-For` e o tempo de resposta do PHP no fim da linha.
- O log de acesso grava o caminho sem a query string e troca por `[oculto]` o resto do caminho dos links de redefinição de senha (`/reset-password/...`) e de confirmação de e-mail (`/email/verify/...`). Assim a busca por nome ou e-mail, o e-mail e a assinatura dos links e os tokens não vão para o CloudWatch nem para o Loki.
- O log de erro do Nginx não tem formato configurável: em toda falha de uma requisição ele grava a requisição inteira (query string e token) e o Referer, que pode ser o próprio link de nova senha, com o token. Por isso o `default.conf` deixa nele só as falhas graves (`error_log stderr crit`). As comuns, como o PHP-FPM fora do ar ou lento (502 e 504), não são gravadas ali; o status e o `$upstream_response_time` delas continuam no log de acesso, e a causa se investiga no log do `app`. Numa falha grave (falta de memória, de arquivos abertos ou de portas, ou a pasta `public/` sumindo da imagem), a requisição inteira ainda pode sair nesse log. É raro, e o risco fica registrado no `docs/nuvem/SEGURANCA.md`, "Riscos aceitos". Os erros que esse log deixou de gravar aparecem nos alarmes de 5xx do ALB e das instâncias (`terraform/monitoring.tf`) e no painel de status do Grafana local. As mensagens de subida e parada do Nginx não mudam.
- `server_tokens off`, gzip para HTML, texto, CSS, JS e JSON e `client_max_body_size 8m` (igual ao `post_max_size` do PHP).
- `fastcgi_connect_timeout 2s` (branch `feat/observabilidade`): com o container `app` parado, o endereço dele some da rede e abrir a conexão fica esperando. Com o padrão de 60 s, cada requisição ficava presa até o 504; com 2 s, o Nginx responde 504 em 2 s (e 502 na hora, enquanto o container ainda existe e só o PHP-FPM caiu). Com o app no ar, a conexão abre na hora, mesmo com todos os processos ocupados, e a espera pela resposta continua sendo o `fastcgi_read_timeout` de 60 s. Medição em `load-tests/resiliencia/README.md`.
- `/build/` com cache de um ano e `immutable`: o nome de cada arquivo do Vite muda quando o conteúdo muda.
- Só o `index.php` vai para o PHP-FPM; outros `.php` respondem 404 e arquivos ocultos (`.env`, `.htaccess`) respondem 403. O `fastcgi_hide_header X-Powered-By` garante que o header não passa.
- Métricas (`stub_status`) na porta 8081, que não é publicada.
- Sem `real_ip`: quem decide em qual proxy confiar é o Laravel.
- Os headers de segurança ficam na aplicação, com uma exceção: os arquivos que o Nginx entrega sozinho (`/build/`, `favicon.ico`, `robots.txt` e o resto de `public/`) saem com `X-Content-Type-Options: nosniff`, porque nunca passam pelo Laravel. As respostas do `index.php` não recebem esse header do Nginx, para não sair duplicado quando a aplicação passar a mandá-lo.

## O que mudou em relação ao compose antigo

| Antes | Agora |
|---|---|
| Os volumes montavam `../barber-shop-main`, uma pasta fora do repositório, e a stack não subia | O dev monta a própria pasta do repositório; a produção leva o código dentro da imagem |
| `php:8.2-fpm`, mas o projeto exige PHP 8.4 | `php:8.4.27-fpm-bookworm` |
| Tags que mudam sozinhas: `composer:latest`, `nginx:alpine`, `mysql:8.0` | Versões fixas; MySQL 8.4 LTS, a mesma linha do RDS (a 8.0 saiu do suporte padrão do RDS em 31/07/2026) |
| Extensões sem uso (`mbstring` compilada de novo, `exif`, `pcntl`, `bcmath`, `gd`) | Só o que a aplicação usa: `pdo_mysql`, `redis` e OPcache |
| `version: '3.8'` e `container_name` fixo | Sem os dois: o `version` está obsoleto e o `container_name` impede dois projetos ao mesmo tempo |
| MySQL aberto na porta 3306 de todas as interfaces | Porta 3306 só no 127.0.0.1 |
| Só app, Nginx e MySQL | Mais Redis, agendador (`scheduler`) e o serviço `assets` para o Vite |
| Sem healthcheck | Healthcheck em todos os serviços, `depends_on` esperando ficar saudável, redes `frontend` e `backend` e `no-new-privileges` |
| Headers `X-Frame-Options`, `X-XSS-Protection` e `X-Content-Type-Options` no Nginx | Os headers de segurança ficam na aplicação (`App\Http\Middleware\SecurityHeaders`, desde a `feat/auth`), conferidos na homolog em 15/11/2026 (`docs/nuvem/SEGURANCA.md`). Os arquivos estáticos (`/build/`, `favicon.ico`, `robots.txt`) saem com o `X-Content-Type-Options: nosniff` do Nginx |
| Pasta do site em `/var/www/public` | `/var/www/html/public` |

O volume do banco antigo não é reaproveitado: o projeto novo (`barberflow-dev`) cria um banco vazio, e o entrypoint do dev roda as migrations.

## Credenciais de teste do MySQL

- O compose de dev continua com o mesmo banco, o mesmo usuário e as mesmas senhas do compose antigo, nas mesmas variáveis (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD`). Foi decisão do Leonam em 13/11: nenhuma senha muda. Elas estão em `docker-compose.yml:16-19`, no bloco `x-mysql-credentials`, e o app conecta com esses mesmos valores.
- Elas valem só para o MySQL deste computador: a porta fica presa no 127.0.0.1. O risco de uma senha conhecida no repositório fica registrado no `docs/nuvem/SEGURANCA.md`, "Riscos aceitos".
- A homolog não guarda senha no repositório. As do usuário do app e do usuário `exporter` vêm do `docker/homolog/homolog.env` (seção "A chave e as senhas da homolog"), que o Git ignora. O root ganha uma senha aleatória quando o banco é criado (o MySQL mostra essa senha uma vez no log) e só entra de dentro do container do banco; nada na homolog usa o root.
- Na AWS a senha do banco é outra e chega à EC2 pelo SSM, nunca por estes arquivos.

## Problemas comuns

- **`port is already allocated` na 8080**: outra stack está no ar (dev, homolog ou outro projeto). Confira com `docker ps` e derrube a outra.
- **502 Bad Gateway**: o Nginx está no ar, mas o PHP-FPM do `app` caiu ou está reiniciando. Veja o motivo em `docker compose logs app`. Na subida isso não acontece, porque o `web` só sobe depois que o `app` fica saudável (`ready to handle connections` no log).
- **Erro 500 com "Vite manifest not found"**: falta o `public/build`. Rode `docker compose run --rm assets`.
- **`required variable HOMOLOG_APP_KEY is missing a value`** (ou outra `HOMOLOG_*`): o comando rodou sem o `--env-file` (use a função `homolog`) ou o `docker/homolog/homolog.env` não tem a linha. Se o banco da homolog ainda não existe, gere o arquivo (passo 3). Se já existe, as senhas precisam ser as da primeira subida; a linha que falta, se for do monitoramento, se acrescenta como em "Vindo do jeito antigo".
- **`The file ... homolog.env already exists`** no passo 3 (no Git Bash, `cannot overwrite existing file`): o arquivo já existe e guarda as senhas do banco da homolog. Não gere outro: pule o passo 3.
- **`Access denied for user 'barberflow'` no log do `app` da homolog**: a `HOMOLOG_DB_PASSWORD` não é a mesma da primeira subida, quando o MySQL criou o banco (um `homolog.env` gerado de novo, ou uma variável `HOMOLOG_DB_PASSWORD` velha na janela do terminal, que vale mais que o arquivo). Use a senha da primeira subida. Se ela se perdeu, apague o banco da homolog (só tem dados de teste) e suba de novo: derrube a stack com `homolog down`, rode `docker volume rm barberflow-homolog_dbdata`, apague o `docker/homolog/homolog.env` e refaça o passo 3. Para manter o `homolog.env` que você tem, basta apagar o volume: o banco nasce de novo com as senhas dele. Foi o caminho de 15/11/2026, no computador do Leonam, com o OK dele: o volume da senha perdida foi apagado, e a homolog subiu do zero com o `homolog.env` que já existia (`docs/RELATORIO-TECNICO.md`, seção 4.12).
- **`service "web" has neither an image nor a build context specified`** (ou `"app"`): o `docker-compose.prod.yml` foi usado sem `APP_IMAGE` e `WEB_IMAGE`.
- **`failed to initialize logging driver` no `web`**: o `docker-compose.prod.yml` subiu sozinho fora da EC2, e o `web` tentou mandar os logs para o CloudWatch (driver `awslogs`) sem região, grupo ou credencial da AWS. No computador, use sempre a homolog (os dois arquivos juntos), que troca o driver pelo `json-file`.
- **`APP_KEY inválido` no log do `app`**: a chave não tem 32 bytes. Gere outra com `php artisan key:generate --show` (passo 3 da homolog). Na AWS, veja "Preencher o APP_KEY" no `docs/nuvem/TERRAFORM.md`.
- **O build para no `sha256sum` do `rds-global-bundle.pem`**: a AWS publicou um bundle novo. Veja "Versões fixas".
