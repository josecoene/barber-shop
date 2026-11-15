# BarberFlow

Sistema de gestão e agendamento para barbearias: uma API REST em Laravel, com telas em Blade, rodando em containers e com a infraestrutura da AWS descrita em Terraform.

O projeto é de duas disciplinas: **Arquitetura de Microsserviços e Desenvolvimento de APIs** (a API, as regras e as telas) e **Computação em Nuvem** (containers, Terraform, CI/CD, observabilidade e testes de carga, pedidos no [`Sistema Cloud.pdf`](Sistema%20Cloud.pdf), na raiz).

**Equipe:** Eduardo Romano, Jose Coene, Leonam e Manoel

O objetivo do produto é ajudar a barbearia a reduzir horários vazios e faltas, aumentar a recorrência de clientes e acompanhar o faturamento. A barbearia agenda para quem chega no balcão, e o cliente agenda sozinho pela conta dele.

## O que o sistema faz

| Perfil | O que faz |
|---|---|
| Admin | Tudo: cadastros de serviços, barbeiros e clientes, a agenda de todos os barbeiros, pagamentos (registrar, corrigir e estornar), o lembrete por e-mail e o Dashboard (faturamento, atendimentos, ticket médio, faltas e ocupação) |
| Barbeiro | A própria agenda (agendar, remarcar, mudar o status e mandar o lembrete), os pagamentos dela (registrar e marcar como pago) e os clientes (ver, criar e editar) |
| Cliente | Cria a conta, confirma o e-mail, agenda para si nos horários livres, vê os próprios horários, cancela até 2 horas antes e acompanha a fidelidade |

- **Agenda sem conflito:** ninguém fica em dois lugares ao mesmo tempo, nem o barbeiro nem o cliente, mesmo com pedidos simultâneos (provado com 50 pedidos iguais no mesmo instante: 1 passou).
- **Lembrete por e-mail** 24 horas antes, pelo agendador, e o "Enviar lembrete agora" na Agenda.
- **Fidelidade:** a cada 10 atendimentos concluídos, o seguinte sai grátis.
- Os números do expediente, da agenda (janela de cancelamento, agendamentos ativos do cliente e dias à frente), da fidelidade e do lembrete automático, e os limites de requisição da API e do login, ficam no `.env`, passando pelo `config/barberflow.php`. Os limites do cadastro de conta e do "esqueci a senha" (5 por minuto) e os do "Enviar lembrete agora" (10 por minuto por usuário e um a cada 10 minutos por agendamento) são fixos no próprio `config/barberflow.php`.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend / API | Laravel 13 (PHP 8.4.1+, testado em PHP 8.5) |
| Banco de dados | MySQL 8.4 (SQLite em memória nos testes e, se quiser, no local) |
| ORM | Eloquent |
| Autenticação | Laravel Sanctum: sessão (cookie + CSRF) nas telas e token Bearer na API |
| Telas | Blade + Bootstrap 5.3 via Vite, todas ligadas à API |
| Containers | Docker (imagem em estágios), Docker Compose e Nginx |
| Cache e sessões | Redis (homolog) e ElastiCache Valkey (AWS) |
| Infraestrutura | Terraform na AWS: VPC, ALB, Auto Scaling, RDS, ElastiCache, ECR, SSM e CloudWatch |
| CI/CD | GitHub Actions |
| Observabilidade | Prometheus, Grafana, Loki e Alloy (homolog) e alarmes do CloudWatch (AWS) |
| Testes | PHPUnit 12 e k6 (carga) |

A arquitetura é um monólito modular: um serviço Laravel só, com as regras de cada módulo em `app/Services`. O porquê, e quando separar um serviço, está no [ADR 0001](docs/adr/0001-monolito-modular.md).

## Documentação

| Quero | Onde |
|---|---|
| Entender a arquitetura, as regras e o modelo de dados | [`docs/ARQUITETURA.md`](docs/ARQUITETURA.md) |
| Ver cada rota da API, com exemplos | [`docs/API.md`](docs/API.md) e a coleção do Postman [`docs/BarberFlow.postman_collection.json`](docs/BarberFlow.postman_collection.json) |
| Ler o relatório final (API, as 4 sprints de nuvem, as evidências e as faltas da auditoria) | [`docs/RELATORIO-TECNICO.md`](docs/RELATORIO-TECNICO.md) |
| Preparar a apresentação | [`docs/APRESENTACAO.md`](docs/APRESENTACAO.md) |
| Ver as telas | [`docs/prints-telas/`](docs/prints-telas/README.md) |
| Rodar com Docker (dev e homolog) | [`docs/nuvem/DOCKER.md`](docs/nuvem/DOCKER.md) |
| A nuvem: desenho, Terraform, CI/CD, monitoramento, segurança, custos e backup | [`docs/nuvem/`](docs/nuvem/ARQUITETURA-NUVEM.md) (lista na seção "Nuvem") |
| Testes de carga e de resiliência | [`load-tests/README.md`](load-tests/README.md) e [`load-tests/resiliencia/README.md`](load-tests/resiliencia/README.md) |
| Regras para quem programa (e para agentes de IA) | [`AGENTS.md`](AGENTS.md) |

## Como rodar

São quatro jeitos:

| Jeito | Para quê | Precisa de |
|---|---|---|
| 1. Sem Docker, tudo nativo | Desenvolver | PHP, Composer e Node na máquina |
| 2. Windows sem PHP | Desenvolver (o jeito usado hoje pelo grupo) | Docker Desktop e Node na máquina |
| 3. Docker de desenvolvimento | Desenvolver com MySQL, Redis e Nginx, como na produção | Só o Docker Desktop |
| 4. Homologação | Ensaiar a produção, com as imagens de produção | Só o Docker Desktop |

### 1. Sem Docker: tudo nativo

**Requisitos**

- PHP 8.4.1 ou superior, com `pdo_sqlite` (para usar SQLite) ou `pdo_mysql` (para usar MySQL)
- Outras extensões do PHP habilitadas no `php.ini`: `mbstring`, `fileinfo`, `curl`, `openssl`, `tokenizer`, `xml`, `ctype`
- Para rodar os testes: `pdo_sqlite` e `sqlite3` (os testes usam SQLite em memória)
- Composer 2
- Node 22.13 ou superior, com npm
- MySQL, só se for usar MySQL

**Passos**

1. Instale as dependências do PHP:
   ```bash
   composer install
   ```
2. Crie o arquivo de ambiente (o `.env.example` vem configurado para MySQL, e cada variável tem um comentário dizendo para que serve):
   ```bash
   cp .env.example .env        # Linux/macOS/Git Bash
   copy .env.example .env      # Windows (cmd)
   ```
3. Gere a chave da aplicação:
   ```bash
   php artisan key:generate
   ```
4. Escolha o banco no `.env`:
   - **SQLite** (o mais simples): troque `DB_CONNECTION=mysql` por `DB_CONNECTION=sqlite` e comente a linha `DB_DATABASE` com `#`. Os dados ficam no arquivo `database/database.sqlite`.
   - **MySQL**: preencha `DB_PASSWORD` com a senha do seu MySQL.
5. Crie as tabelas:
   ```bash
   php artisan migrate
   ```
   Se o banco ainda não existir (o arquivo do SQLite ou o banco `barberflow` no MySQL), o comando pergunta se pode criá-lo: responda `yes`.
6. Popule os dados iniciais:
   ```bash
   php artisan db:seed
   ```
   O seed cria os 4 serviços de exemplo e o usuário de teste `test@example.com`, com o perfil admin (veja [Entrando no sistema](#entrando-no-sistema)). Pode rodar de novo sem duplicar nada, mas os 4 serviços de exemplo voltam ao preço, à duração e ao status padrão: o que você mudou neles pela tela se perde. Os outros cadastros não mudam.
7. Instale as dependências do front e gere os arquivos das telas:
   ```bash
   npm install
   npm run build
   ```
   Se for mexer nas telas, use `npm run dev` num terminal separado no lugar do `npm run build`: ele atualiza a página a cada mudança.
8. Suba o servidor:
   ```bash
   php artisan serve
   ```

Atalhos do Composer:

- `composer run setup` faz de uma vez o `composer install`, cria o `.env` se ele não existir e roda o `key:generate`, o `migrate`, o `npm install` e o `npm run build`. Ele não roda o seed. Como o `.env` novo vem com MySQL, crie e ajuste o `.env` antes (passos 2 e 4).
- `composer run dev` sobe juntos o servidor (`php artisan serve`), a fila (`queue:listen`) e o Vite (`npm run dev`).

### 2. Windows sem PHP: PHP no container, Node na máquina

O container usa a imagem `composer:2`, que traz o PHP com `pdo_sqlite` mas sem `pdo_mysql`, por isso este caminho usa SQLite.

**Requisitos:** Docker Desktop, Node 22.13 ou superior e npm. Rode os comandos no PowerShell, na raiz do projeto.

1. Instale as dependências do PHP pelo container. A pasta `vendor` fica no volume `barberflow-vendor` do Docker, e não na sua máquina:
   ```powershell
   docker run --rm -v "${PWD}:/app" -v barberflow-vendor:/app/vendor -w /app composer:2 composer install
   ```
2. Crie o `.env`:
   ```powershell
   Copy-Item .env.example .env
   ```
   Depois, no `.env`, troque `DB_CONNECTION=mysql` por `DB_CONNECTION=sqlite` e comente a linha `DB_DATABASE` com `#`.
3. Suba o servidor num container chamado `barberflow-app`:
   ```powershell
   docker run -d --name barberflow-app -p 127.0.0.1:8000:8000 -e PHP_CLI_SERVER_WORKERS=4 -v "${PWD}:/app" -v barberflow-vendor:/app/vendor -w /app composer:2 php artisan serve --host=0.0.0.0 --port=8000 --no-reload
   ```
   O `127.0.0.1` no `-p` abre a porta só para a sua máquina. Com `-p 8000:8000`, o Docker escuta em todas as interfaces e qualquer pessoa na mesma rede (o Wi-Fi da faculdade, por exemplo) alcança o app: a API pede login, mas o usuário de teste tem uma senha que está no código, então quem souber dela entra como admin. O `--host=0.0.0.0` vale só dentro do container e precisa ficar: sem ele, o Docker não alcança o servidor. O `PHP_CLI_SERVER_WORKERS=4` deixa o servidor atender várias requisições ao mesmo tempo e só funciona junto com o `--no-reload`.
4. Rode os comandos `artisan` dentro do container, com `docker exec -w /app barberflow-app ...`:
   ```powershell
   docker exec -w /app barberflow-app php artisan key:generate
   docker exec -w /app barberflow-app php artisan migrate
   docker exec -w /app barberflow-app php artisan db:seed
   docker restart barberflow-app
   ```
   Com `--no-reload`, o servidor só lê o `.env` quando inicia. Por isso o `docker restart` no fim: repita sempre que mudar o `.env`.
5. Na máquina, instale as dependências do front e deixe o Vite rodando:
   ```powershell
   npm install
   npm run dev
   ```
   Se não for mexer nas telas, rodar `npm run build` uma vez também serve.

Para parar o servidor, use `docker stop barberflow-app`; para subir de novo, `docker start barberflow-app`.

### 3. Docker de desenvolvimento (`docker-compose.yml`)

Sobe o banco MySQL 8.4, o Redis, o app (PHP-FPM com o código montado), o agendador e o Nginx, no projeto `barberflow-dev`. Na raiz do repositório:

```
docker compose run --rm assets
docker compose up -d --wait
```

O primeiro comando gera o `public/build` num container com Node 24; o segundo sobe tudo e espera ficar saudável. Na primeira subida, o `app` cria o `.env`, instala o `vendor`, gera a chave e roda as migrations (nunca o seed). Depois abra http://localhost:8080/login. Os comandos do dia a dia (seed, testes, Tinker, logs) estão no [`docs/nuvem/DOCKER.md`](docs/nuvem/DOCKER.md), "Desenvolvimento".

Pare com `docker compose down`, sem `-v`: o `-v` apaga também o banco, o Redis e o `vendor`.

### 4. Homologação: as imagens de produção

A homolog roda as mesmas imagens que vão para a AWS (`APP_ENV=production`, disco só de leitura, cache e sessão no Redis), com o MySQL no lugar do RDS e o Mailpit no lugar do e-mail de verdade, no projeto `barberflow-homolog`. É onde a entrega final rodou os testes de carga, a resiliência, o ZAP e os prints.

No PowerShell, na raiz do repositório:

```powershell
docker compose down
docker build -f docker/php/Dockerfile --target app -t barberflow-app:homolog .
# Só na primeira vez: gere o docker/homolog/homolog.env (passo 3 do docs/nuvem/DOCKER.md, "Subir")
function homolog { docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -p barberflow-homolog @args }
homolog up -d --build --wait
```

- O `docker/homolog/homolog.env` guarda a chave e as senhas da homolog, geradas aleatórias uma vez só. O Git e o build ignoram o arquivo.
- Telas em http://localhost:8080 e os e-mails que a aplicação manda em http://localhost:8025 (Mailpit). As portas ficam só no 127.0.0.1.
- O banco nasce vazio. O admin e os dados de demonstração (3 barbeiros com login, 15 clientes, 4 com conta, e 30 dias de agenda com pagamentos) vêm do `AdminUserSeeder` e do `DemoSeeder`, com as senhas só no ambiente do comando: [`docs/nuvem/DOCKER.md`](docs/nuvem/DOCKER.md), "Dados de demonstração e administrador".
- Com o monitoramento (Grafana em http://localhost:3000 e Prometheus em http://localhost:9090), use a função `homologmon` do [`docs/nuvem/MONITORAMENTO.md`](docs/nuvem/MONITORAMENTO.md). Para o k6, a `homologcarga` do [`load-tests/README.md`](load-tests/README.md).
- Pare com a mesma função que subiu, sem `-v`: `homolog down` (ou `homologmon down`).

Dev e homolog usam a mesma porta (8080): suba um de cada vez.

### Depois de subir

- Telas: o endereço do app (http://localhost:8000 nos jeitos 1 e 2, http://localhost:8080 nos jeitos 3 e 4) leva ao login. Depois de entrar, cada perfil cai na tela inicial dele: o admin no Dashboard, o barbeiro na Agenda e o cliente em Meus horários. O menu mostra só as telas do perfil:
  - admin: Dashboard (faturamento, atendimentos, ticket médio, faltas e ocupação do mês, de outro mês ou de um período), Agenda, Agendar, Pagamentos, Clientes, Barbeiros e Serviços;
  - barbeiro: Agenda (só a coluna dele), Agendar (na própria agenda), Pagamentos (só os da agenda dele, sem corrigir nem estornar) e Clientes;
  - cliente: Agendar, Meus horários (a fidelidade dele, o "Cancelar" até 2 horas antes e se cada atendimento está pago) e Minha conta.
- Pagamento: o atendimento concluído ganha o "Registrar pagamento" na tela de Pagamentos e no detalhe da Agenda, com o valor já preenchido pelo preço do agendamento. Corrigir e estornar ficam com o admin.
- Lembrete: o detalhe da Agenda mostra quando saiu o lembrete por e-mail e tem o "Enviar lembrete agora" (admin e o barbeiro do agendamento). O lembrete automático sai pelo agendador (veja [Lembretes e agendador](#lembretes-e-agendador)).
- Fidelidade: a cada 10 atendimentos concluídos, o seguinte sai grátis. Clientes tem a coluna Fidelidade (ex.: 7/10, com o selo de prêmio disponível), o modal de pagamento oferece "Usar o prêmio de fidelidade" quando o cliente tem direito, e o cliente vê o próprio progresso em Meus horários.
- API: o endereço do app com `/api`. Sem login, só o `/health` responde:
  ```bash
  curl http://localhost:8000/api/health
  ```
  As outras rotas pedem o token do `POST /api/login` (exemplos em [`docs/API.md`](docs/API.md#autenticação)).

> **Erro 500 "Vite manifest not found":** as telas carregam o CSS e o JS pelo Vite. Se o `npm run build` não rodou e o `npm run dev` não está aberto, as telas respondem 500 com essa mensagem. Rode um dos dois (no jeito 3, `docker compose run --rm assets`). A API não depende disso.

### Entrando no sistema

**No ambiente local** (jeitos 1, 2 e 3), entre com o usuário de teste `test@example.com`, que o `php artisan db:seed` cria com o perfil admin. A senha dele está em `database/seeders/DatabaseSeeder.php`. Ela é conhecida de propósito, só para testes (decisão do Leonam, 13/11/2026: nenhuma senha que já existe muda): num servidor de verdade, nunca rode o `db:seed` completo. Lá, use só o `AdminUserSeeder` (abaixo) e, se quiser os serviços de exemplo, o `ServiceSeeder`.

Com o admin você cadastra o resto:

- **Barbeiro:** em Barbeiros, preencha "Senha de acesso" para criar o login dele (o e-mail do cadastro vira o login). A coluna "Acesso" mostra quem tem login.
- **Cliente:** cada cliente cria a própria conta em "Criar conta", na tela de login, e confirma o e-mail pelo link que recebe. No local, o `.env` usa `MAIL_MAILER=log`: os e-mails (confirmação e "esqueci minha senha") não saem da máquina e aparecem no fim do `storage/logs/laravel.log`, de onde dá para copiar o link.

**Na homologação** (jeito 4), entre com o admin da homolog ou com os logins de demonstração (barbeiros e clientes com o domínio `@demo.barberflow.test`). As senhas ficam no `docker/homolog/homolog.env`; a lista de logins está no [`docs/nuvem/DOCKER.md`](docs/nuvem/DOCKER.md), "Dados de demonstração e administrador".

**Em produção**, o `APP_URL` precisa ser o endereço público do app: fora do local, os links dos e-mails (confirmação e "esqueci minha senha") e os redirecionamentos sempre começam por ele. Atrás do ALB com `https`, defina também o `TRUSTED_PROXIES`, senão o link de confirmação de e-mail não vale.

O primeiro admin vem do `AdminUserSeeder`, com a senha escolhida por quem instala:

1. No ambiente do comando (ou no `.env` do servidor), defina `ADMIN_NAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD`.
2. Rode `php artisan db:seed --class=AdminUserSeeder --force`. Na AWS, pelo Session Manager: [`docs/nuvem/TERRAFORM.md`](docs/nuvem/TERRAFORM.md), "Criar o administrador".
3. Entre com esse e-mail, troque a senha em Minha conta se quiser e apague o `ADMIN_PASSWORD`.

O `AdminUserSeeder` nunca altera um usuário que já existe: rodar de novo não troca a senha nem o perfil de ninguém. Sem e-mail, sem senha ou com uma senha de menos de 8 caracteres (a regra das outras senhas do sistema), ele só avisa e não cria nada.

> **Atenção:** `clients` e `barbers` começam vazios no local: o seed cria só os serviços e o usuário de teste. O `DemoSeeder`, com barbeiros, clientes e agenda, é só da homologação (ele só roda com `DEMO_SEED_ENABLED=true`).
> Se você já tinha rodado as migrations antes de atualizar o projeto, rode `php artisan migrate`: as migrations novas só acrescentam colunas e tabelas. Recriar o banco com `php artisan migrate:fresh` apaga os dados.

### Lembretes e agendador

O lembrete por e-mail dos agendamentos sai pela rodada do agendador do Laravel, a cada 15 minutos (`routes/console.php`): ela manda o lembrete de quem tem horário (agendado ou confirmado) nas próximas `REMINDER_HOURS_BEFORE` horas (padrão 24) e ainda não recebeu. O comando `appointments:send-reminders` faz a mesma rodada na mão:

```bash
php artisan appointments:send-reminders
```

Ele mostra quantos lembretes saíram, quantos foram pulados (cliente excluído, e-mail que não recebe mensagem ou lembrete que outro envio pegou antes) e quantos falharam, por exemplo `Enviados: 1. Pulados: 0. Com falha: 0.`. Os que falharam voltam a ser tentados na rodada seguinte.

A rodada automática só acontece se algo chamar o agendador:

- **no local**, deixe num terminal à parte `php artisan schedule:work` (no jeito 2, `docker exec -it -w /app barberflow-app php artisan schedule:work`). Ele fica rodando e chama o agendador a cada minuto; pare com Ctrl+C. Nos jeitos 3 e 4, o container `scheduler` já faz isso;
- **num servidor sem containers**, o cron chama o agendador a cada minuto: `* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1`.

A rodada roda dentro do processo do agendador, então o log dela vai para o mesmo lugar que o do resto da aplicação: o `storage/logs/laravel.log` no local e, nos containers (com o log no `stderr`), a saída do `scheduler` (`docker compose logs scheduler`). Quando um lembrete falha, o log ganha um aviso só com o id do agendamento e uma linha `ERROR` só com as contas da rodada; a linha da rodada na saída do agendador sai `DONE` mesmo assim (um detalhe do Laravel 13.33), então procure o `ERROR`.

Sem o agendador, o lembrete automático não sai, mas o "Enviar lembrete agora" do detalhe da Agenda continua funcionando. No local, com `MAIL_MAILER=log`, os lembretes vão para o fim do `storage/logs/laravel.log`, como os outros e-mails, inteiros e com o endereço do cliente: por isso o `log` só serve no local, e fora dele o `MAIL_MAILER` precisa enviar de verdade (`smtp`; na homolog, o Mailpit). O e-mail mostra o nome da barbearia (`BARBERSHOP_NAME`, ou o `APP_NAME` quando vazio) e, quando preenchidos, o endereço (`BARBERSHOP_ADDRESS`) e o telefone (`BARBERSHOP_PHONE`).

A fidelidade usa `LOYALTY_VISITS` (a cada quantos atendimentos concluídos o cliente ganha um prêmio, padrão 10) e `LOYALTY_DISCOUNT_PERCENT` (o desconto do prêmio, padrão 100, o atendimento grátis). As regras e os exemplos estão em [`docs/API.md`](docs/API.md#fidelidade).

### Atualizando um ambiente que já existia

Quem já tinha o projeto rodando antes do login precisa de alguns ajustes:

1. Rodar `php artisan migrate` (no jeito 2, `docker exec -w /app barberflow-app php artisan migrate`). Ele só acrescenta colunas (`deleted_at`, `users.role`, `users.phone`, `barbers.user_id`, `clients.user_id`, `appointments.reminder_sent_at`, `payments.discount` e `payments.loyalty_reward`), as tabelas `appointments` e `payments` e índices, e não apaga nada.
2. Rodar `php artisan db:seed` de novo: ele dá o perfil admin ao `test@example.com` sem mudar a senha. Sem perfil, o usuário não entra.
3. No `.env`, trocar `APP_LOCALE=en` por `APP_LOCALE=pt_BR` e acrescentar `APP_TIMEZONE=America/Sao_Paulo`. Sem isso, as mensagens da API continuam em inglês.
4. Copiar do `.env.example` as variáveis novas: as do login, da segurança, da agenda, da fidelidade e do lembrete (`SANCTUM_*`, `API_RATE_LIMIT`, `LOGIN_*`, `ADMIN_*`, `TRUSTED_*`, `SECURITY_CSP`, `CORS_ALLOWED_ORIGINS`, `CLIENT_CANCEL_MIN_HOURS`, `CLIENT_MAX_ACTIVE_APPOINTMENTS`, `BOOKING_DAYS_AHEAD`, o expediente em `BUSINESS_*`, `LOYALTY_*`, `REMINDER_HOURS_BEFORE` e os dados da barbearia em `BARBERSHOP_*`) e as da entrega final (os timeouts `DB_CONNECT_TIMEOUT`, `DB_READ_TIMEOUT`, `REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT` e `MAIL_TIMEOUT`, o `MYSQL_ATTR_SSL_CA` e o `RUN_MIGRATIONS` dos containers e o `DEMO_SEED_ENABLED` e a `DEMO_USER_PASSWORD` da homologação). Vazias, valem os padrões descritos nos comentários do `.env.example`.
5. Rodar `npm install` (o Tailwind saiu do projeto) e reiniciar o servidor (no jeito 2, `docker restart barberflow-app`).

## Testes

### No SQLite (`composer test`)

```bash
composer test
```

No jeito 2, rode `docker exec -w /app barberflow-app composer test`; no jeito 3, `docker compose exec app composer test`.

O `composer test` limpa o cache de configuração antes de rodar o `php artisan test`. Com a configuração em cache, os testes ignorariam o `phpunit.xml` e usariam o banco do `.env`, apagando os dados dele. Por garantia, o `tests/TestCase.php` também recusa rodar num banco que não seja o SQLite em memória ou um banco com "test" no nome (`TestDatabaseGuardTest`).

As regras que os testes supõem (expediente, limites, janela de cancelamento, fidelidade, lembrete e dados da barbearia) ficam fixas nos padrões pelo `tests/TestCase.php`, então o `.env` montado junto com o projeto não muda o resultado.

### No MySQL (`composer test:mysql`)

O SQLite em memória não trava linha, então as travas da agenda (dois agendamentos no mesmo horário ao mesmo tempo), do registro de pagamento, do prêmio de fidelidade e da confirmação do e-mail (contra o cadastro de cliente da barbearia com o mesmo e-mail) só valem no MySQL. O `composer test:mysql` roda a suíte inteira com o `phpunit.mysql.xml`, que lê `DB_HOST`, `DB_PORT`, `DB_DATABASE` (padrão `barberflow_testing`), `DB_USERNAME` e `DB_PASSWORD` do ambiente. Só nele rodam o `AppointmentConcurrencyTest`, o `PaymentConcurrencyTest`, o `LoyaltyConcurrencyTest` e o `EmailVerificationConcurrencyTest`, que abrem duas conexões como dois pedidos ao mesmo tempo e conferem as travas em passos de ordem fixa. A prova com pedidos simultâneos de verdade (vários processos) é o teste de carga `concorrencia.js`.

> **Atenção:** os testes apagam e recriam o banco. Use só um MySQL descartável, nunca o banco de desenvolvimento ou da homologação. O `tests/TestCase.php` recusa banco sem "test" no nome.

No Windows sem PHP, com um MySQL 8.4 e um PHP 8.4 temporários, que somem no fim (a imagem `composer:2` não tem `pdo_mysql`). No PowerShell, na raiz do projeto, com o `vendor` do jeito 2:

```powershell
$senha = [guid]::NewGuid().ToString('N')   # senha descartável, só desta rodada
docker network create barberflow-test
docker run -d --name barberflow-mysql-test --network barberflow-test `
  -e MYSQL_RANDOM_ROOT_PASSWORD=yes -e MYSQL_DATABASE=barberflow_testing `
  -e MYSQL_USER=barberflow_test -e MYSQL_PASSWORD=$senha mysql:8.4
# Espere o "docker logs barberflow-mysql-test" mostrar "ready for connections" na porta 3306.
docker run --rm --network barberflow-test -v "${PWD}:/app" -v barberflow-vendor:/app/vendor:ro -w /app `
  -e DB_HOST=barberflow-mysql-test -e DB_USERNAME=barberflow_test -e DB_PASSWORD=$senha php:8.4-cli `
  sh -c "docker-php-ext-install pdo_mysql > /dev/null && php artisan config:clear && php vendor/bin/phpunit -c phpunit.mysql.xml"
docker rm -f barberflow-mysql-test
docker network rm barberflow-test
```

Com PHP e MySQL na máquina (jeito 1), crie um banco de teste vazio (ex.: `barberflow_testing`) com um usuário só para ele, defina `DB_USERNAME` e `DB_PASSWORD` no ambiente do terminal e rode `composer test:mysql`.

### A mesma rodada do CI

O CI (`.github/workflows/ci.yml`) roda em todo pull request, a partir de um checkout limpo: sem `vendor`, sem `node_modules`, sem `public/build` e sem `.env`. O job `PHP` faz `composer install`, cria o `.env` a partir do `.env.example` com uma chave nova e roda o Pint, o `composer test` (com cobertura) e o `composer audit`. Ele não roda o `npm run build` de propósito: um teste que abre tela sem `withoutVite()` falha ali.

Para repetir o job `PHP` no computador, num clone limpo e num container descartável (nada volta para a pasta do projeto). No PowerShell, na raiz do repositório:

```powershell
$ci = Join-Path $env:TEMP 'barberflow-ci'
git clone --quiet --branch (git branch --show-current) . $ci
docker run --rm -v "${ci}:/src:ro" composer:2 sh -c "cp -a /src/. /app/ && cd /app && composer install --no-interaction --no-progress && cp .env.example .env && php artisan key:generate && vendor/bin/pint --test && composer test && composer audit"
```

- O clone leva só o que já tem commit, como o CI. Mudanças ainda sem commit ficam de fora. Numa rodada nova, troque o nome da pasta (o `git clone` recusa uma que já existe).
- O CI usa o PHP 8.4; a imagem `composer:2` traz o 8.5. A suíte passa nos dois.
- O `composer audit` falha hoje com um alerta de severidade baixa no `laravel/framework` (veja [Pendências](#pendências)).
- Os outros jobs (MySQL, front, imagens com Trivy, Terraform e actionlint) estão no [`docs/nuvem/CI-CD.md`](docs/nuvem/CI-CD.md), "Rodar o mesmo no computador".

### O que os testes cobrem

Os testes de feature ficam em `tests/Feature`, um arquivo por recurso ou assunto:

| Assunto | Arquivos | O que conferem |
|---|---|---|
| Cadastros | `ServiceApiTest`, `ClientApiTest` e `BarberApiTest` | Listagem paginada (`data`, `links` e `meta`), busca, `page` e `per_page` inválidos, limites de cada campo, exclusão lógica (com a anonimização do cliente e a troca do e-mail e do telefone do barbeiro), recadastro do e-mail de quem foi excluído, o mesmo 422 de e-mail em uso para dois pedidos com o mesmo e-mail no mesmo instante, 404 e o login do barbeiro criado pelo campo `password` |
| Agenda | `AppointmentApiTest`, `AppointmentAuthorizationTest`, `AvailableSlotsTest` e `AppointmentConcurrencyTest` (só MySQL) | Término calculado, expediente, fatias, passado, domingo, limite de 60 dias, sobreposição por barbeiro e por cliente (com o horário encostado permitido), remarcação, transições de status, filtros, o 409 ao excluir cadastro com agendamento futuro, as permissões de cada perfil, os horários livres com o relógio fixo e as travas no MySQL |
| Pagamentos | `PaymentApiTest`, `PaymentAuthorizationTest` e `PaymentConcurrencyTest` (só MySQL) | Só atendimento concluído, um pagamento por atendimento (mesmo depois do estorno), valor padrão, `paid_at` do servidor, transições, correção pelo admin, filtros e totais, permissões e a trava no MySQL |
| Fidelidade | `LoyaltyApiTest` e `LoyaltyConcurrencyTest` (só MySQL) | Visitas, prêmio pela config, desconto em centavos, recusa sem prêmio, o atendimento que está sendo pago fora da conta, estorno que devolve o prêmio, a listagem sem uma consulta por cliente e a trava no MySQL |
| Lembretes | `ReminderCommandTest`, `ReminderApiTest` e `ReminderMailTest` | A janela de 24 horas, sem repetir, sem cliente excluído, a reserva da linha contra rodadas e envios simultâneos, a falha que libera para a rodada seguinte com só o id no log, a rodada do agendador no próprio processo (com a falha no log dele, só com o id e as contas), o "Enviar lembrete agora" com os limites e o 503, e o e-mail sem dado pessoal |
| Dashboard | `DashboardApiTest` | Os números de uma semana conhecida, um a um, o corte grátis fora do faturamento, a ocupação, o mês atual como padrão, o limite de 366 dias e só o admin vendo |
| Login e permissões | `AuthApiTest`, `AuthorizationTest`, `WebAuthTest`, `RegistrationTest`, `PasswordResetTest` e `EmailVerificationConcurrencyTest` (só MySQL) | Token com prefixo e validade, limite de tentativas, logout, troca de senha que derruba as outras sessões, 401 em todas as rotas, 403 para cada perfil, conta de cliente com confirmação de e-mail (com o 422 de e-mail em uso também para dois cadastros no mesmo instante, a recusa do e-mail com acento, a confirmação que não liga a conta a um cadastro de cliente com um e-mail só parecido, com a comparação do MySQL imitada no SQLite, e a confirmação que espera o cadastro de cliente da barbearia com o mesmo e-mail, ou é esperada por ele, no MySQL) e "esqueci minha senha" |
| Segurança e erros | `SecurityHeadersTest`, `RateLimitTest`, `ApiErrorsTest`, `LocalizationTest`, `HealthApiTest`, `DatabaseErrorLogTest`, `MailErrorLogTest`, `ConnectionTimeoutTest` e `LostConnectionWriteTest` | Headers, CSP, HSTS só em HTTPS, CORS, hosts aceitos, links pelo `APP_URL`, limites de requisição, erros em português, idioma e fuso, o `/api/health` com o banco ou o cache fora, o erro de banco no log sem o SQL, a falha no envio de e-mail no log sem o endereço (com o "esqueci a senha" respondendo o mesmo e o cadastro de conta criando a conta com um aviso, que a tela não repete ao recarregar), os timeouts de conexão, o limite de tempo do servidor de e-mail (`MAIL_TIMEOUT`), o limite de leitura do MySQL só nas requisições web e o cadastro de serviço, de cliente e de conta que perde a conexão depois de gravar, sem sair em dobro nem virar o 422 de e-mail em uso |
| Telas | `WebPagesTest` | Cada tela responde para o perfil que a usa, o layout com o token CSRF e o Sair, o menu de cada perfil (numa linha só, com o nome inteiro para o leitor de tela), a lista dos cadastros no celular, o e-mail da tela "Confirme seu e-mail" quebrando só depois do @ e antes dos pontos, os valores que as telas recebem do servidor, as páginas de erro em português, nenhum estilo inline e nenhum nome de rota repetido |
| Seeds e banco | `DatabaseSeederTest`, `DemoSeederTest`, `SqliteConnectionTest` e `TestDatabaseGuardTest` | O seed repetível, o admin inicial só com a senha da regra das contas, a demonstração (só com a flag e a senha, sem duplicar nem mudar o que já existe, seguindo as regras da agenda), o SQLite que espera a vez e a recusa de banco que não é de teste |

### Resultado na entrega final (15/11/2026)

| Rodada | Resultado |
|---|---|
| `composer test` (SQLite, PHP 8.5) | 745 passaram e 9 pulados (as travas, que só valem no MySQL), 8535 asserções |
| `composer test:mysql` (MySQL 8.4.13, PHP 8.4.27) | 754 passaram, nenhum pulado, 8565 asserções |
| Igual ao CI, numa cópia sem `vendor`, `public/build` e `.env` | 745 passaram e 9 pulados, 8535 asserções |
| Pint e `npm run build` | Sem erro |
| `npm audit` e `composer audit` | `npm audit` sem vulnerabilidade; `composer audit` com 1 alerta baixo (veja [Pendências](#pendências)) |

## Endpoints

Base URL: o endereço do app com `/api`. Todas as rotas pedem o token Bearer do login, menos login, cadastro de conta, esqueci a senha, redefinição e health. O que cada perfil pode fazer está em [`docs/API.md`](docs/API.md#perfis-e-permissões).

| Recurso | Método | Rota | Descrição |
|---|---|---|---|
| Health | GET | `/health` | Confere banco e cache (200 ou 503) |
| Auth | POST | `/login` | Entra e devolve o token Bearer (vale 480 minutos) |
| Auth | POST | `/logout` | Apaga o token usado na chamada |
| Auth | GET | `/user` | Mostra o usuário logado, com o perfil |
| Auth | PUT | `/user/password` | Troca a senha (pede a senha atual) |
| Auth | POST | `/register` | Cria a conta de cliente e manda o e-mail de confirmação |
| Auth | POST | `/email/verification-notification` | Reenvia o e-mail de confirmação |
| Auth | POST | `/forgot-password` | Manda o link de "esqueci minha senha" |
| Auth | POST | `/reset-password` | Redefine a senha com o token do link |
| Services | GET | `/services` | Lista os serviços, paginado (`search`, `status`, `per_page`, `page`) |
| Services | POST | `/services` | Cria um serviço |
| Services | GET | `/services/{id}` | Mostra um serviço |
| Services | PUT/PATCH | `/services/{id}` | Atualiza um serviço |
| Services | DELETE | `/services/{id}` | Exclui um serviço (exclusão lógica) |
| Clients | GET | `/clients` | Lista os clientes, paginado (`search`, `per_page`, `page`), cada um com a fidelidade em `loyalty` |
| Clients | POST | `/clients` | Cadastra um cliente |
| Clients | GET | `/clients/{id}` | Mostra um cliente |
| Clients | PUT/PATCH | `/clients/{id}` | Atualiza um cliente |
| Clients | DELETE | `/clients/{id}` | Exclui um cliente e apaga os dados pessoais dele (LGPD) |
| Clients | GET | `/clients/{id}/loyalty` | Fidelidade do cliente: visitas, progresso e prêmios (`appointment_id` opcional, a conta do pagamento dele) |
| Barbers | GET | `/barbers` | Lista os barbeiros, paginado (`search`, `per_page`, `page`) |
| Barbers | POST | `/barbers` | Cadastra um barbeiro |
| Barbers | GET | `/barbers/{id}` | Mostra um barbeiro |
| Barbers | PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro |
| Barbers | DELETE | `/barbers/{id}` | Exclui um barbeiro (mantém o nome, libera o e-mail e apaga o telefone) |
| Barbers | GET | `/barbers/{id}/available-slots` | Horários livres do barbeiro no dia para um serviço (`date`, `service_id`) |
| Appointments | GET | `/appointments` | Lista os agendamentos em ordem de data e horário (`date`, `from`, `to`, `barber_id`, `client_id`, `status`, `without_payment`, `per_page`, `page`) |
| Appointments | POST | `/appointments` | Agenda, com o término e o preço calculados pelo servidor |
| Appointments | GET | `/appointments/{id}` | Mostra um agendamento |
| Appointments | PUT/PATCH | `/appointments/{id}` | Remarca (data, horário, barbeiro ou serviço) e muda as observações |
| Appointments | PATCH | `/appointments/{id}/status` | Muda o status (confirmar, iniciar, concluir, cancelar, faltou) |
| Appointments | POST | `/appointments/{id}/reminder` | Envia o lembrete por e-mail na hora (admin e o barbeiro do agendamento; um a cada 10 minutos por agendamento, mesmo depois de remarcar, e até 10 por minuto por usuário) |
| Payments | GET | `/payments` | Lista os pagamentos do atendimento mais recente para o mais antigo, com os totais do filtro (`from`, `to`, `method`, `status`, `barber_id`, `client_id`, `per_page`, `page`) |
| Payments | POST | `/payments` | Registra o pagamento de um atendimento concluído (valor padrão: o preço do agendamento; com `use_loyalty_reward`, usa o prêmio da fidelidade) |
| Payments | GET | `/payments/{id}` | Mostra um pagamento |
| Payments | PUT/PATCH | `/payments/{id}` | Corrige valor, forma e observações (só o admin) |
| Payments | PATCH | `/payments/{id}/status` | Marca como pago ou estorna (o estorno é só do admin) |
| Dashboard | GET | `/dashboard` | Números do período (`from` e `to`, padrão o mês atual, até 366 dias), só para o admin |

Excluir cliente, barbeiro ou serviço com agendamento futuro ainda ativo dá `409`, e a tela mostra a mensagem da API.

Corpos de requisição, regras de validação, códigos de resposta e exemplos completos estão em [`docs/API.md`](docs/API.md). Há também uma coleção do Postman em [`docs/BarberFlow.postman_collection.json`](docs/BarberFlow.postman_collection.json) (importe no Postman ou no Insomnia).

## Nuvem

A parte de nuvem segue as 4 sprints do `Sistema Cloud.pdf`: containers e Terraform, dados gerenciados, CI/CD e observabilidade com go-live. **Nada foi aplicado na AWS:** aplicar precisa da conta do grupo e custa dinheiro. O Terraform é validado só no código (`terraform validate`, `terraform test` com o provider simulado e `trivy config`), e a homolog local roda as mesmas imagens de produção, com o monitoramento, os testes de carga e a resiliência. O que cada sprint pedia e onde está no repositório fica no [relatório técnico](docs/RELATORIO-TECNICO.md), seção "A nuvem".

| Guia | O que tem |
|---|---|
| [`docs/nuvem/ARQUITETURA-NUVEM.md`](docs/nuvem/ARQUITETURA-NUVEM.md) | O desenho na AWS, o caminho de uma requisição e a homolog local lado a lado com a AWS |
| [`docs/nuvem/DOCKER.md`](docs/nuvem/DOCKER.md) | A imagem em estágios, o dev, a homolog, os dados de demonstração e a produção na EC2 |
| [`docs/nuvem/TERRAFORM.md`](docs/nuvem/TERRAFORM.md) | Rede, dados e segurança, computação, observabilidade, como validar sem conta da AWS e o primeiro deploy |
| [`docs/nuvem/CI-CD.md`](docs/nuvem/CI-CD.md) | O CI, o deploy por OIDC, o rollback e a configuração do GitHub |
| [`docs/nuvem/DEPLOY.md`](docs/nuvem/DEPLOY.md) | Onde está cada passo do deploy |
| [`docs/nuvem/MONITORAMENTO.md`](docs/nuvem/MONITORAMENTO.md) | Prometheus, Grafana, Loki e os alarmes do CloudWatch |
| [`docs/nuvem/SEGURANCA.md`](docs/nuvem/SEGURANCA.md) | Diretrizes, LGPD, o checklist OWASP API Top 10 com as evidências e os riscos aceitos |
| [`docs/nuvem/BACKUP.md`](docs/nuvem/BACKUP.md) | Backup, restore, RPO e RTO do banco |
| [`docs/nuvem/CUSTOS.md`](docs/nuvem/CUSTOS.md) | Quanto a AWS custa por mês e como destruir depois da apresentação |
| [`docs/nuvem/go-live-checklist.md`](docs/nuvem/go-live-checklist.md) | O que conferir antes de abrir para clientes de verdade |
| [`load-tests/README.md`](load-tests/README.md) | O k6: smoke, carga, estresse e a concorrência da agenda |
| [`load-tests/resiliencia/README.md`](load-tests/resiliencia/README.md) | Banco, app e cache caindo e voltando |

Para validar o Terraform sem conta da AWS, crie a função `tf` do [`docs/nuvem/TERRAFORM.md`](docs/nuvem/TERRAFORM.md), seção "Validar e testar sem conta da AWS", e rode `tf fmt -check -recursive`, `tf init -backend=false`, `tf validate` e `tf test`. Nunca rode `apply` sem o grupo combinar: ele cria recursos que cobram por hora.

## Estrutura do projeto

```
app/
  Console/Commands/   # appointments:send-reminders (a rodada do lembrete por e-mail, para rodar na mão)
  Enums/              # UserRole (perfis), AppointmentStatus (status e transições da agenda),
                      # PaymentStatus (status e transições do pagamento) e PaymentMethod (formas)
  Exceptions/         # 429 do login bloqueado (TooManyLoginAttemptsException) e 422 do e-mail
                      # em uso no mesmo instante (EmailTakenException)
  Http/Controllers/   # ServiceController, ClientController, BarberController, AppointmentController,
                      # AvailableSlotController (horários livres), PaymentController, DashboardController,
                      # HealthController e AuthController (login da API)
    Auth/             # login, cadastro, confirmação de e-mail e senha pelas telas
  Http/Middleware/    # SecurityHeaders e EnsureEmailIsVerified
  Listeners/          # liga a conta ao cadastro de cliente quando o e-mail é confirmado
  Mail/               # AppointmentReminder (o e-mail do lembrete, em Markdown)
  Models/             # Service, Client, Barber, Appointment, Payment, User
    Concerns/         # ReleasesEmailOnDelete e HasAppointments (409 ao excluir com agendamento futuro)
  Policies/           # quem pode o quê em cada recurso
  Rules/              # regras de validação próprias (NotOnlySpaces)
  Services/           # AppointmentScheduler (agenda), ReminderSender (lembrete), PaymentRecorder (pagamento),
                      # LoyaltyService (fidelidade), DashboardMetrics (dashboard) e Auth/ (login, cadastro e senha)
bootstrap/app.php     # rotas, middlewares e erros (JSON em pt-BR e o erro de banco no log sem o SQL)
config/barberflow.php # limites, segurança, admin inicial, expediente, fidelidade, lembrete, dados da
                      # barbearia e o seed de demonstração, lidos do .env
database/
  migrations/         # estrutura das tabelas
  seeders/            # DatabaseSeeder (usuário de teste), AdminUserSeeder, ServiceSeeder e DemoSeeder
resources/
  views/              # telas em Blade (layouts, auth, account, appointments, payments, dashboard, errors e cadastros)
    mail/             # o e-mail do lembrete
  js/pages.js         # comportamento das telas e chamadas à API
  css/pages.css       # estilos próprios (a CSP não deixa usar style="")
routes/
  api.php             # rotas da API (Route::apiResource), com nomes api.*
  web.php             # rotas das telas
  console.php         # agendador: a rodada do lembrete a cada 15 minutos e a limpeza dos tokens vencidos
tests/
  Feature/            # testes dos endpoints, da agenda, da autenticação, das telas e dos seeders
  Support/            # LostConnectionStatement (o comando que perde a conexão depois de gravar)
phpunit.xml           # testes no SQLite em memória (composer test)
phpunit.mysql.xml     # a mesma suíte num MySQL descartável (composer test:mysql)
docker/               # php/ (Dockerfile, entrypoints e ajustes do PHP), nginx/, mysql/ e homolog/
docker-compose.yml    # desenvolvimento (projeto barberflow-dev)
docker-compose.*.yml  # prod (EC2), homolog, monitoring (Prometheus, Grafana e Loki) e carga (k6)
terraform/            # AWS: rede, dados, segurança, computação, CI/CD, monitoramento, testes e bootstrap
.github/              # CI, deploy e Dependabot
monitoring/           # configuração do Prometheus, do Grafana, do Loki e do Alloy
load-tests/           # k6, roteiro de resiliência, resultados e os da Sprint 4
docs/
  API.md              # referência da API
  ARQUITETURA.md      # arquitetura, regras, topologia, modelo de dados e plano
  RELATORIO-TECNICO.md
  APRESENTACAO.md
  adr/                # decisões de arquitetura
  nuvem/              # guias da nuvem
  prints-telas/       # prints das telas de cada perfil e do visitante
Sistema Cloud.pdf     # o pedido da disciplina de nuvem
```

## Status do projeto

**API e regras**

- [x] Ambiente configurado (Laravel 13, PHP 8.4, MySQL 8.4)
- [x] Migrations: `users`, `barbers`, `clients`, `services`, `appointments` e `payments`
- [x] CRUD de `services`, `clients` e `barbers`, com testes de feature
- [x] Paginação e busca nas listagens
- [x] Exclusão lógica (soft delete), com anonimização do cliente (LGPD)
- [x] Mensagens em português do Brasil e fuso de São Paulo
- [x] `GET /api/health` (banco e cache)
- [x] Autenticação (sessão nas telas e token na API) e permissões por perfil (Policies e Gates)
- [x] Conta de cliente com confirmação de e-mail e "esqueci minha senha"
- [x] Limites de requisição, headers de segurança, CSP e CORS restrito
- [x] Agendamentos: agenda do dia por barbeiro, Agendar da barbearia e do cliente, Meus horários
- [x] Horários livres (`GET /api/barbers/{id}/available-slots`)
- [x] Sem sobreposição de horário por barbeiro e por cliente, com trava contra pedidos simultâneos (conferida no MySQL pelo `AppointmentConcurrencyTest` e com 10 e 50 pedidos de verdade pelo `concorrencia.js`)
- [x] Limites do agendamento: até 60 dias à frente e até 3 agendamentos ativos por cliente (configuráveis)
- [x] Status do atendimento (agendado, confirmado, em atendimento, concluído, cancelado e faltou) e cancelamento pelo cliente até 2 horas antes
- [x] Pagamentos: um por atendimento concluído, com o valor padrão do agendamento, as formas dinheiro, Pix e cartões, os status pendente, pago e estornado (estorno e correção só pelo admin), a tela de Pagamentos com filtros e totais, o atalho no detalhe da Agenda e o "Pago" em Meus horários
- [x] Dashboard básico (só o admin, a tela inicial dele): faturamento total, por forma e por dia, atendimentos por status, ticket médio, taxa de faltas, ocupação, top 5 serviços e resumo por barbeiro, no mês atual, no mês passado ou num período de até 366 dias
- [x] Lembrete por e-mail: a rodada do agendador a cada 15 minutos (e o comando `appointments:send-reminders`, para rodar na mão), nas 24 horas antes do horário (configurável), sem repetir, sem sair para cliente excluído e com a falha no log do agendador, e o "Enviar lembrete agora" no detalhe da Agenda
- [x] Fidelidade: a cada 10 atendimentos concluídos, o seguinte sai grátis (configurável), com o progresso em Clientes e em Meus horários e o "Usar o prêmio de fidelidade" no modal de pagamento
- [x] Seed de demonstração para a homologação (`DemoSeeder`) e admin de produção com a senha do ambiente (`AdminUserSeeder`)
- [x] Erro de banco e falha no envio de e-mail no log sem dado pessoal (sem o SQL e sem o endereço), timeouts curtos de conexão com o banco e o cache, limite de leitura do MySQL nas requisições web e limite de tempo do servidor de e-mail
- [x] `docs/API.md` e a coleção do Postman com todas as rotas

**Telas**

- [x] Login, cadastro de conta, "esqueci minha senha" e páginas de erro em português
- [x] Menu e tela inicial por perfil (admin, barbeiro e cliente)
- [x] Dashboard, Agenda, Agendar, Pagamentos, Clientes, Barbeiros, Serviços, Minha conta e, para o cliente, Agendar e Meus horários
- [x] Celular (390 px) sem rolagem lateral, com a Agenda, Pagamentos e os cadastros em lista, e acessibilidade básica (campos obrigatórios, erros ligados aos campos e "Pular para o conteúdo")
- [x] Prints das telas de cada perfil (admin, barbeiro e cliente) e das telas abertas do visitante, em 1280 e 390 px ([`docs/prints-telas/`](docs/prints-telas/README.md))

**Nuvem (`Sistema Cloud.pdf`)**

- [x] Sprint 1: imagem Docker em estágios, compose de dev, produção e homolog, Nginx, Terraform com versões fixas, state remoto e a rede (VPC e subnets em 2 zonas)
- [x] Sprint 2: RDS MySQL 8.4 com TLS, backup de 7 dias e snapshot final, ElastiCache, security groups, IAM mínimo, ECR e parâmetros do SSM (validado só no código)
- [x] Sprint 3: imagens otimizadas, CI (Pint, testes no SQLite e no MySQL, `composer audit`, `npm audit`, build, Trivy, Terraform e actionlint) e deploy por tag com OIDC, instance refresh e rollback (validado só no código)
- [x] Sprint 4: Prometheus, Grafana, Loki e Alloy na homolog, alarmes e dashboard do CloudWatch, testes de carga e de resiliência, checklist de go-live e os guias de `docs/nuvem/`
- [ ] Terraform aplicado na AWS e o go-live (a parte B do checklist): precisa da conta do grupo e custa dinheiro
- [ ] CI verde no GitHub, proteção da `main` e pull requests: depois do push, com o grupo

**Entrega final (`feat/entrega-final`)**

- [x] As duas trilhas juntas (aplicação e nuvem)
- [x] README, `AGENTS.md`, `docs/ARQUITETURA.md`, ADR do monólito modular, relatório técnico, roteiro da apresentação e checklist OWASP preenchido
- [x] Verificação completa: suíte no SQLite, no MySQL e igual à do CI, homolog com as imagens de produção, k6, resiliência, headers, CSRF no navegador, OWASP ZAP, Terraform e os prints
- [ ] `laravel/framework` 13.40.0 (alerta GHSA-63hm-g96r-2m2q): espera o OK do Leonam
- [x] Queda do banco e "subir de novo sem perder nada" com o MySQL num volume de verdade (um volume novo, `barberflow-homolog_dbdata-r`, porque o antigo tem a senha perdida)
- [x] Rodada de acabamento: o menu numa linha só, os cadastros em lista no celular e os blocos curtos da Agenda, com os prints refeitos; o 422 do e-mail repetido no mesmo instante, provado nos testes; e o cadastro de conta com o e-mail fora do ar e o limite de leitura do MySQL, medidos de novo na homolog (k6, ZAP, headers e CSRF ficaram os da verificação)
- [x] Segunda rodada de acabamento: o limite de tempo do servidor de e-mail (`MAIL_TIMEOUT`) e o log do lembrete na saída do agendador, medidos de novo na homolog com o servidor de e-mail congelado; o e-mail da tela "Confirme seu e-mail" no celular; a confirmação do e-mail contra o cadastro de cliente da barbearia, provada sem erro no MySQL; e a confirmação que, no MySQL, ligava a conta ao cadastro de cliente de outra pessoa com um e-mail só parecido (com acento), com o cadastro de conta passando a recusar e-mail com acento (os prints, o k6, o ZAP, os headers e o CSRF ficaram os de antes)
- [ ] O volume antigo da homolog (`barberflow-homolog_dbdata`, senha perdida): apagar ou trocar espera o OK do Leonam

## Prints das telas

Os 44 prints (admin, barbeiro, cliente e visitante, cada tela em 1280 e em 390 px) estão em [`docs/prints-telas/`](docs/prints-telas/README.md), com o endereço de cada tela e o que cada print mostra. Foram tirados em 15/11/2026 na homolog, com os dados do `DemoSeeder`, e 22 deles foram refeitos no mesmo dia, depois das correções da rodada de acabamento. Nenhum mostra senha, token ou e-mail de verdade.

## Limitações conhecidas

- O usuário de teste `test@example.com` tem uma senha conhecida, que está no `DatabaseSeeder`. Serve só para o ambiente local: em produção, o admin vem do `AdminUserSeeder` e o seed de teste nunca roda.
- O cadastro de conta avisa quando o e-mail já tem conta ("Este e-mail já está cadastrado."). O login e o "esqueci minha senha" não revelam isso.
- As listagens devolvem no máximo 100 itens por página. A tela Agendar carrega os 100 primeiros clientes, barbeiros e serviços ativos em ordem de nome. A Agenda e Meus horários leem até 1000 agendamentos.
- Os horários livres olham só a agenda do barbeiro. Se o cliente já tem horário com outro barbeiro no mesmo período, o Agendar recusa ao salvar, com a mensagem no campo de cliente.
- Cada atendimento tem um pagamento só: depois do estorno, ele não recebe outro. O dashboard conta tudo pela data do atendimento, não pela do pagamento.
- O lembrete é só por e-mail e sai na hora, sem fila: a rodada manda um de cada vez, e, com o servidor de e-mail congelado, cada um espera até o `MAIL_TIMEOUT` (10 segundos). Sem o agendador rodando (`schedule:work` no local, o container `scheduler` nos compose, cron num servidor), o lembrete automático não sai.
- O prêmio de fidelidade vale para qualquer serviço (o desconto é sobre o preço do atendimento pago) e não vence.
- No SQLite local, um pedido grava por vez: o `config/database.php` usa transações `IMMEDIATE` e espera até 5 segundos pela vez (`busy_timeout`), em vez de responder "database is locked" quando as telas fazem várias chamadas ao mesmo tempo.
- Com o MySQL de pé e sem responder (congelado), cada pedido que lê o banco espera até cerca de 4 vezes o `DB_READ_TIMEOUT` (20 segundos com o padrão de 5) antes do erro, e ocupa um processo do PHP-FPM nesse tempo. Com 10 pedidos assim ao mesmo tempo, os processos de um container acabam, e até o `/up` espera (16,8 segundos na homolog). As migrations, o agendador e os seeders, na linha de comando, ficam sem esse limite (`docs/ARQUITETURA.md`, "Quedas do banco e do cache" e "Limitações e riscos conhecidos").
- Com o servidor de e-mail de pé e sem responder (congelado), quem manda e-mail (o cadastro de conta, o reenvio do link, o "esqueci a senha" e o "Enviar lembrete agora") espera até o `MAIL_TIMEOUT` (10 segundos, ou até 20 se ele congelar no meio da conversa) e ocupa um processo do PHP-FPM nesse tempo, sempre abaixo dos 60 segundos do Nginx. Na homolog, o reenvio do link respondeu 503 em 10 segundos.
- Sem domínio e certificado, o ALB atenderia só em HTTP. O Terraform já aceita o `certificate_arn` (`docs/nuvem/TERRAFORM.md`, "HTTPS com domínio próprio").

## Pendências

Esperam o OK do Leonam (detalhes no [relatório técnico](docs/RELATORIO-TECNICO.md), seção "Pendências"):

- **`laravel/framework`:** o `composer audit` acusa o GHSA-63hm-g96r-2m2q, de severidade baixa, na 13.33.0. A correção é a 13.40.0. Até a atualização, o passo `composer audit` do job `PHP` do CI falha.
- **Volume do banco da homolog:** o `barberflow-homolog_dbdata` do computador do Leonam foi criado com uma senha que se perdeu. A verificação usou o MySQL num tmpfs e, para o "subir de novo sem perder nada" e a queda do banco, um volume novo (`barberflow-homolog_dbdata-r`, com os dados do `DemoSeeder`). Apagar ou trocar o antigo, e apagar o novo se ele não for mais usado, espera a decisão dele.
- **Arquivo solto na worktree Nuvem:** um arquivo sem uso na raiz da outra worktree do Orca, fora deste repositório. O nome dele fica fora deste documento, porque tem o formato de uma senha gerada.
