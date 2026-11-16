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
| `.dockerignore` | O que não entra no build (vendor, node_modules, `.env`, caches do dev, testes) |

## Antes de começar

- Docker Desktop com Compose 2.24.4 ou mais novo (`docker compose version`). Os arquivos usam o `!override` do Compose e o `start_interval` dos healthchecks, que pede o Docker Engine 25 ou mais novo. Testado no Windows 11 com Docker 29.5.3 e Compose v5.1.4.
- Internet no primeiro build: as imagens, os pacotes do Composer e do npm e a fonte Instrument Sans, que o `vite.config.js` de hoje baixa no build.
- Não precisa de PHP, Composer, MySQL nem Node no computador.
- Dev e homolog usam a mesma porta (8080). Suba um de cada vez.

## Portas

| Porta | Onde | Serviço | Quem acessa |
|---|---|---|---|
| 8080 | dev e homolog | `web` (Nginx) | Só este computador (127.0.0.1) |
| 3306 | dev | `db` (MySQL) | Só este computador, para DBeaver ou MySQL Workbench |
| 8025 | homolog | `mailpit` (tela dos e-mails) | Só este computador |
| 80 | produção | `web` (Nginx) | O ALB da AWS |
| 9000, 6379, 1025 e 8081 | todos | PHP-FPM, Redis, SMTP do Mailpit e métricas do Nginx | Só a rede interna do Docker |

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

### Subir

No PowerShell, na raiz do repositório:

```
docker compose down
docker build -f docker/php/Dockerfile --target app -t barberflow-app:homolog .
$env:HOMOLOG_APP_KEY = (docker run --rm --entrypoint php barberflow-app:homolog artisan key:generate --show --no-ansi)
$env:HOMOLOG_DB_PASSWORD = (docker run --rm --entrypoint php barberflow-app:homolog -r "echo bin2hex(random_bytes(24));")
docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog up -d --build --wait
```

1. Derruba o dev, se estiver no ar (os dois usam a porta 8080).
2. Faz o build da imagem `app`, que é onde o `artisan` mora.
3. Gera uma chave nova e uma senha nova para o usuário do app no MySQL e guarda nas variáveis `HOMOLOG_APP_KEY` e `HOMOLOG_DB_PASSWORD`, sem mostrar na tela. As variáveis valem só nessa janela do terminal. No Git Bash, os mesmos passos são `export HOMOLOG_APP_KEY="$(docker run --rm --entrypoint php barberflow-app:homolog artisan key:generate --show --no-ansi)"` e `export HOMOLOG_DB_PASSWORD="$(docker run --rm --entrypoint php barberflow-app:homolog -r 'echo bin2hex(random_bytes(24));')"`.
4. Sobe a stack: o `app` roda o `php artisan optimize` e as migrations (`migrate --force --isolated`). Nunca roda seed.

Depois abra http://localhost:8080/login. Os e-mails que a aplicação mandar aparecem em http://localhost:8025.

**Enquanto o banco da homolog existir, reaproveite os dois valores.** O MySQL grava a senha do usuário do app só na primeira subida, quando cria o banco: com outra `HOMOLOG_DB_PASSWORD`, o app não entra mais no banco (veja "Problemas comuns"). Com outra chave, as sessões abertas deixam de valer.

Para não depender da janela do terminal, guarde os dois valores no `.env` da raiz do repositório logo depois do passo 3. O Git ignora esse arquivo, e o compose lê dele os valores dos `${...}`. Nas próximas vezes, pule o passo 3:

```
Add-Content .env "`nHOMOLOG_APP_KEY=$env:HOMOLOG_APP_KEY`nHOMOLOG_DB_PASSWORD=$env:HOMOLOG_DB_PASSWORD"
```

### Comandos

Todos os comandos da homolog levam os dois arquivos e o nome do projeto. O compose lê o arquivo inteiro até para derrubar, então a `HOMOLOG_APP_KEY` e a `HOMOLOG_DB_PASSWORD` precisam estar definidas (na mesma janela do terminal, ou no `.env` da raiz, elas já estão).

| O que fazer | Comando |
|---|---|
| Ver o estado | `docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog ps` |
| Logs do app | `docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog logs -f app` |
| Situação das migrations | `docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog exec app php artisan migrate:status` |
| Abrir o Tinker | `docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog exec -e HOME=/tmp app php artisan tinker` |
| Parar e manter os dados | `docker compose -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog down` |

O Tinker precisa do `HOME=/tmp` porque o disco do container é só de leitura e ele grava a própria configuração na pasta do usuário.

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

- O `user_data` da instância (branch `feat/infra-computacao`) gera o `.env` ao lado do compose a partir do SSM, com `APP_IMAGE` e `WEB_IMAGE` apontando para o ECR, e roda `docker compose -f docker-compose.prod.yml up -d`.
- Os logs vão para o CloudWatch pelo driver `awslogs`, configurado no `daemon.json` do Docker da EC2. Por isso o compose não tem `logging:`, e a homolog sobe no Docker Desktop sem credencial da AWS.
- O bloco de produção (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`) vale mais que o `.env`. Cache e sessão ficam no Redis porque as travas do `migrate --isolated` e do `onOneServer()` precisam de um cache que todas as instâncias enxerguem.
- `SESSION_SECURE_COOKIE` é `true` por padrão. Sem HTTPS no ALB, defina `SESSION_SECURE_COOKIE=false`, senão o login não segura a sessão.
- Com `RUN_MIGRATIONS=true` no `.env`, o `app` roda `php artisan migrate --force --isolated` ao subir. Com várias instâncias subindo juntas, só uma migra. O `scheduler` nunca migra.
- Cada instância roda o seu `scheduler`; o `onOneServer()` garante que cada tarefa rode uma vez só.
- O disco dos containers é só de leitura. O Laravel escreve só em pastas em memória (`bootstrap/cache`, `storage/framework`, `storage/logs` e `/tmp`), que começam vazias em cada container novo. O entrypoint recria as subpastas do `storage/framework` antes do `optimize`.
- Modo de manutenção: `docker compose -f docker-compose.prod.yml exec app php artisan down` (e `php artisan up` para voltar). Com o driver padrão (`file`), o aviso fica na memória daquele container: vale só para a instância em que o comando rodou e some quando o container é recriado. Para valer em todas as instâncias, ponha `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` no `.env` antes de subir os containers (a config fica congelada na subida). Aí o `down` e o `up` rodados numa instância valem para todas, e cada requisição passa a consultar o Redis. O `/up` fica fora da manutenção e continua respondendo 200 para o ALB.
- O bundle de CA do RDS fica em `/etc/ssl/certs/rds-global-bundle.pem`. Com `MYSQL_ATTR_SSL_CA` apontando para ele (o parâmetro do SSM vem na branch `feat/infra-dados-seguranca`), o Laravel conecta no banco por TLS.
- O entrypoint de produção para com uma mensagem clara se a `APP_KEY` vier vazia. Ele nunca gera chave, nunca roda seed e nunca muda dono de arquivo.
- Healthchecks: o `app` pergunta `/ping` ao PHP-FPM, o `web` lê a página de métricas do Nginx e o `scheduler` roda `php artisan schedule:list`. O ALB olha o `/up` do Laravel.

## A imagem por dentro

| Estágio | O que faz |
|---|---|
| `composer` | Só fornece o binário do Composer 2.11.2 |
| `base` | PHP-FPM 8.4.27 com `pdo_mysql`, `redis` e OPcache (já vem ligado na imagem oficial; o build confere), `php.ini-production`, os `.ini` do BarberFlow, o bundle de CA do RDS, o pool e o healthcheck |
| `dev` | `base` com Composer e `unzip`; o OPcache volta a conferir a data dos arquivos |
| `vendor` | `composer install --no-dev --no-scripts` e `composer dump-autoload --optimize --no-dev` |
| `assets` | `npm ci` e `npm run build` no Node 24 (gera o `public/build`) |
| `web` | Nginx com o `default.conf`, o `public/` e o `public/build` |
| `app` | `base` com o código, o `vendor` e o `public/build`, tudo do usuário `www-data`; roda `php artisan package:discover` no build e sobe como `www-data` |

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

Para atualizar, troque a versão no arquivo e rode o build de novo. A extensão redis e o bundle do RDS também têm o SHA-256 no Dockerfile (`PHPREDIS_SHA256` e `RDS_CA_BUNDLE_SHA256`). Quando a AWS publicar um bundle novo, o build para na conferência: baixe o arquivo, confira de onde veio e calcule o hash novo (`Get-FileHash -Algorithm SHA256 arquivo` no PowerShell, `sha256sum arquivo` no Git Bash).

### PHP e PHP-FPM

- `expose_php = Off`: some o header `X-Powered-By`.
- OPcache ligado com os ajustes da rodada otimizada da Sprint 4 (`otimizado.ini`): 128 MB, até 20 mil arquivos e cache de caminhos. Na produção ele não confere a data dos arquivos (`validate_timestamps=0`), porque o código só muda com imagem nova. No dev confere em toda requisição.
- Pool `www` em `pm = dynamic`, com até 10 processos (cada um usa por volta de 30 a 50 MB) e reciclagem a cada 500 requisições. O `docker.conf` oficial continua valendo: logs no stderr e `clear_env = no`, para o Laravel enxergar as variáveis do container.
- Na imagem `app` o PHP-FPM roda como `www-data`, então ele avisa no log que ignora as linhas `user` e `group` do pool. É esperado.

### Nginx

- Log com o mesmo começo da Sprint 4 (o painel do Grafana lê o `$request_time` nessa posição), mais o `X-Forwarded-For` e o tempo de resposta do PHP no fim da linha.
- O log de acesso grava o caminho sem a query string e troca por `[oculto]` o resto do caminho dos links de redefinição de senha (`/reset-password/...`) e de confirmação de e-mail (`/email/verify/...`). Assim a busca por nome ou e-mail, o e-mail e a assinatura dos links e os tokens não vão para o CloudWatch nem para o Loki. O log de erro do Nginx (num 502 ou 504, por exemplo) não tem formato configurável e ainda grava a requisição inteira.
- `server_tokens off`, gzip para HTML, texto, CSS, JS e JSON e `client_max_body_size 8m` (igual ao `post_max_size` do PHP).
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
| Headers `X-Frame-Options`, `X-XSS-Protection` e `X-Content-Type-Options` no Nginx | Os headers de segurança passam para a aplicação, na branch `feat/auth`; até ela entrar, as páginas e a API saem sem esses headers. Os arquivos estáticos (`/build/`, `favicon.ico`, `robots.txt`) já saem com o `X-Content-Type-Options: nosniff` do Nginx |
| Pasta do site em `/var/www/public` | `/var/www/html/public` |

O volume do banco antigo não é reaproveitado: o projeto novo (`barberflow-dev`) cria um banco vazio, e o entrypoint do dev roda as migrations.

## Credenciais de teste do MySQL

- O compose de dev continua com o mesmo banco, o mesmo usuário e as mesmas senhas do compose antigo, nas mesmas variáveis (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD`). Foi decisão do Leonam em 13/11: nenhuma senha muda. Elas estão em `docker-compose.yml:16-19`, no bloco `x-mysql-credentials`, e o app conecta com esses mesmos valores.
- Elas valem só para o MySQL deste computador: a porta fica presa no 127.0.0.1. O risco de uma senha conhecida no repositório fica registrado no `docs/nuvem/SEGURANCA.md` (branch `feat/observabilidade`).
- A homolog não guarda senha no repositório. A do usuário do app vem da `HOMOLOG_DB_PASSWORD` (passo 3 da homolog). O root ganha uma senha aleatória quando o banco é criado (o MySQL mostra essa senha uma vez no log) e só entra de dentro do container do banco; nada na homolog usa o root.
- Na AWS a senha do banco é outra e chega à EC2 pelo SSM, nunca por estes arquivos.

## Problemas comuns

- **`port is already allocated` na 8080**: outra stack está no ar (dev, homolog ou outro projeto). Confira com `docker ps` e derrube a outra.
- **502 Bad Gateway**: o Nginx está no ar, mas o PHP-FPM do `app` caiu ou está reiniciando. Veja o motivo em `docker compose logs app`. Na subida isso não acontece, porque o `web` só sobe depois que o `app` fica saudável (`ready to handle connections` no log).
- **Erro 500 com "Vite manifest not found"**: falta o `public/build`. Rode `docker compose run --rm assets`.
- **`required variable HOMOLOG_APP_KEY is missing a value`** (ou `HOMOLOG_DB_PASSWORD`): a variável não está definida nessa janela do terminal nem no `.env` da raiz. Se o banco da homolog ainda não existe, refaça o passo 3. Se já existe, use a mesma `HOMOLOG_DB_PASSWORD` da primeira subida.
- **`Access denied for user 'barberflow'` no log do `app` da homolog**: a `HOMOLOG_DB_PASSWORD` não é a mesma da primeira subida, quando o MySQL criou o banco. Use a senha da primeira subida. Se ela se perdeu, apague o banco da homolog (só tem dados de teste) e suba de novo: derrube a stack com o `down` da tabela acima, rode `docker volume rm barberflow-homolog_dbdata` e refaça o passo 3.
- **`service "web" has neither an image nor a build context specified`** (ou `"app"`): o `docker-compose.prod.yml` foi usado sem `APP_IMAGE` e `WEB_IMAGE`.
- **O build para no `sha256sum` do `rds-global-bundle.pem`**: a AWS publicou um bundle novo. Veja "Versões fixas".
