# BarberFlow

Sistema de gestão e agendamento para barbearias, desenvolvido como projeto da disciplina **Arquitetura de Microsserviços e Desenvolvimento de APIs**.

**Equipe:** Eduardo Romano, Jose Coene, Leonam e Manoel

O objetivo do produto é ajudar a barbearia a reduzir horários vazios e faltas, aumentar a recorrência de clientes e acompanhar o faturamento. Esta primeira versão (MVP em construção) expõe uma **API REST** para os cadastros-base e a agenda, com login e permissões por perfil (admin, barbeiro e cliente): a barbearia agenda para quem chega no balcão e o cliente agenda sozinho pela conta dele.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend / API | Laravel 13 (PHP 8.4.1+, testado em PHP 8.5) |
| Banco de dados | MySQL |
| ORM | Eloquent |
| Autenticação | Laravel Sanctum: sessão (cookie + CSRF) nas telas e token Bearer na API |
| Frontend | Telas em Blade + Bootstrap 5.3 via Vite, todas ligadas à API: login, conta, cadastros, Agenda, Agendar e, para o cliente, Agendar e Meus horários |

## Como rodar localmente

Há dois caminhos:

1. **Tudo nativo:** PHP, Composer e Node instalados na máquina.
2. **Windows sem PHP:** o PHP roda num container Docker e o Node fica na máquina.

### Caminho 1: tudo nativo

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
2. Crie o arquivo de ambiente (o `.env.example` vem configurado para MySQL):
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

### Caminho 2: Windows sem PHP (PHP no container, Node na máquina)

É o jeito usado hoje no Windows sem PHP instalado. O container usa a imagem `composer:2`, que traz o PHP com `pdo_sqlite` mas sem `pdo_mysql`, por isso este caminho usa SQLite.

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

### Depois de subir

- Telas: `http://localhost:8000` leva ao login. Depois de entrar, cada perfil cai na tela inicial dele: admin e barbeiro na Agenda, o cliente em Meus horários. O menu mostra só as telas do perfil:
  - admin: Agenda, Agendar, Clientes, Barbeiros e Serviços;
  - barbeiro: Agenda (só a coluna dele), Agendar (na própria agenda) e Clientes;
  - cliente: Agendar, Meus horários (com o "Cancelar" até 2 horas antes) e Minha conta.
- API: `http://localhost:8000/api`. Sem login, só o `/health` responde:
  ```bash
  curl http://localhost:8000/api/health
  ```
  As outras rotas pedem o token do `POST /api/login` (exemplos em [`docs/API.md`](docs/API.md#autenticação)).

> **Erro 500 "Vite manifest not found":** as telas carregam o CSS e o JS pelo Vite. Se o `npm run build` não rodou e o `npm run dev` não está aberto, as telas respondem 500 com essa mensagem. Rode um dos dois. A API não depende disso.

### Entrando no sistema

**No ambiente local**, entre com o usuário de teste `test@example.com`, que o `php artisan db:seed` cria com o perfil admin. A senha dele está em `database/seeders/DatabaseSeeder.php`. Ela é conhecida de propósito, só para testes: num servidor de verdade, nunca rode o `db:seed` completo. Lá, use só o `AdminUserSeeder` (abaixo) e, se quiser os serviços de exemplo, o `ServiceSeeder`.

Com o admin você cadastra o resto:

- **Barbeiro:** em Barbeiros, preencha "Senha de acesso" para criar o login dele (o e-mail do cadastro vira o login). A coluna "Acesso" mostra quem tem login.
- **Cliente:** cada cliente cria a própria conta em "Criar conta", na tela de login, e confirma o e-mail pelo link que recebe. No local, o `.env` usa `MAIL_MAILER=log`: os e-mails (confirmação e "esqueci minha senha") não saem da máquina e aparecem no fim do `storage/logs/laravel.log`, de onde dá para copiar o link.

**Em produção**, o `APP_URL` precisa ser o endereço público do app: fora do local, os links dos e-mails (confirmação e "esqueci minha senha") e os redirecionamentos sempre começam por ele. Atrás do ALB com `https`, defina também o `TRUSTED_PROXIES`, senão o link de confirmação de e-mail não vale.

O primeiro admin vem do `AdminUserSeeder`, com a senha escolhida por quem instala:

1. No `.env` do servidor, preencha `ADMIN_NAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD`.
2. Rode `php artisan db:seed --class=AdminUserSeeder --force`.
3. Entre com esse e-mail, troque a senha em Minha conta se quiser e apague o `ADMIN_PASSWORD` do `.env`.

O `AdminUserSeeder` nunca altera um usuário que já existe: rodar de novo não troca a senha nem o perfil de ninguém. Sem e-mail ou sem senha, ele só avisa.

> **Atenção:** `clients` e `barbers` começam vazios: o seed cria só os serviços e o usuário de teste.
> Se você já tinha rodado as migrations antes de atualizar o projeto, recrie o banco com `php artisan migrate:fresh` (isso apaga os dados).

### Atualizando um ambiente que já existia

Quem já tinha o projeto rodando antes do login precisa de alguns ajustes:

1. Rodar `php artisan migrate` (no caminho 2, `docker exec -w /app barberflow-app php artisan migrate`). Ele só acrescenta colunas (`deleted_at`, `users.role`, `users.phone`, `barbers.user_id` e `clients.user_id`) e a tabela `appointments`, e não apaga nada.
2. Rodar `php artisan db:seed` de novo: ele dá o perfil admin ao `test@example.com` sem mudar a senha. Sem perfil, o usuário não entra.
3. No `.env`, trocar `APP_LOCALE=en` por `APP_LOCALE=pt_BR` e acrescentar `APP_TIMEZONE=America/Sao_Paulo`. Sem isso, as mensagens da API continuam em inglês.
4. Copiar do `.env.example` as variáveis novas do login, da segurança e da agenda (`SANCTUM_*`, `API_RATE_LIMIT`, `LOGIN_*`, `ADMIN_*`, `TRUSTED_*`, `SECURITY_CSP`, `CORS_ALLOWED_ORIGINS`, `CLIENT_CANCEL_MIN_HOURS`, `CLIENT_MAX_ACTIVE_APPOINTMENTS`, `BOOKING_DAYS_AHEAD` e o expediente em `BUSINESS_*`). Vazias, valem os padrões descritos ali.
5. Rodar `npm install` (o Tailwind saiu do projeto) e reiniciar o servidor (no caminho 2, `docker restart barberflow-app`).

## Testes

```bash
composer test
```

No caminho 2, rode `docker exec -w /app barberflow-app composer test`.

O `composer test` limpa o cache de configuração antes de rodar o `php artisan test`. Com a configuração em cache, os testes ignorariam o `phpunit.xml` e usariam o banco do `.env`, apagando os dados dele. Por garantia, o `tests/TestCase.php` também recusa rodar num banco que não seja o SQLite em memória ou um banco com "test" no nome (`TestDatabaseGuardTest`).

Os testes de feature em `tests/Feature` cobrem:

- a agenda (`AppointmentApiTest`): término calculado, expediente, fatias, passado, domingo, limite de 60 dias à frente, serviço inativo, cadastros excluídos, sobreposição por barbeiro e por cliente (com o horário encostado permitido), remarcação, as transições de status, os filtros da listagem, o `409` ao excluir cliente, barbeiro ou serviço com agendamento futuro e as observações apagadas junto com o cliente;
- as permissões da agenda por perfil (`AppointmentAuthorizationTest`): o barbeiro só na própria agenda, o cliente agendando só para si e até 3 agendamentos ativos, vendo só os próprios, sem descobrir serviço inativo ou excluído e cancelando dentro e fora da janela de 2 horas;
- as travas da agenda no MySQL (`AppointmentConcurrencyTest`, só no `composer test:mysql`; no `composer test` ele aparece como pulado): agendamentos sem conflito não esperam um pelo outro, o barbeiro e o cliente ficam travados até o agendamento terminar e o segundo pedido enxerga o que o primeiro gravou;
- os horários livres (`AvailableSlotsTest`, com o relógio fixo pelo `travelTo`), inclusive o horário do próprio agendamento na remarcação;
- o CRUD de `services`, `clients` e `barbers`: listagem paginada (`data`, `links` e `meta`), busca, `page` e `per_page` inválidos, criação, limites de cada campo, consulta, atualização, exclusão lógica (com a anonimização do cliente e a troca do e-mail e do telefone do barbeiro, mesmo quando o endereço reservado já está ocupado), recadastro do e-mail de quem foi excluído, 404 e o login do barbeiro criado pelo campo `password`;
- o login da API e o token (`AuthApiTest`: prefixo, validade de 480 minutos, recusas, limite de tentativas com `Retry-After`, logout, `/api/user` e troca de senha);
- as permissões (`AuthorizationTest`: 401 sem login em todas as rotas, 403 para cada perfil sem permissão e para a conta de cliente sem o e-mail confirmado, e o cliente sem poder mandar o e-mail do próprio cadastro);
- o login pelas telas (`WebAuthTest`: tela inicial por perfil, "lembrar de mim", quem abre cada tela, sessão chamando a API e sessões encerradas quando a senha muda);
- a conta de cliente e a senha (`RegistrationTest` e `PasswordResetTest`: cadastro, confirmação do e-mail, ligação ao cadastro de cliente só depois dela, "esqueci minha senha", redefinição e a conta criada com o e-mail de outra pessoa);
- os cabeçalhos de segurança, o CORS, os hosts aceitos e os links dos e-mails sempre a partir do `APP_URL` (`SecurityHeadersTest`) e os limites de requisição (`RateLimitTest`);
- os erros da API em português (`ApiErrorsTest`: 403 de e-mail não confirmado, 404 de registro e de rota, 405, 419 e 422; o 401 fica no `AuthorizationTest` e o 429 no `RateLimitTest`);
- o idioma e o fuso (`LocalizationTest`);
- o `GET /api/health` com tudo no ar (200), com o banco ou o cache fora (503) e fora do limite de requisições (`HealthApiTest`);
- as telas (`WebPagesTest`: cada tela responde para o perfil que a usa, o layout com o token CSRF e o Sair, o menu de cada perfil, o expediente e as regras de status que a Agenda recebe do servidor, as páginas de erro em português, nenhuma tela com estilo inline e nenhum nome de rota repetido);
- o seed (`DatabaseSeederTest`) e a conexão do SQLite, que espera a vez em vez de dar "database is locked" (`SqliteConnectionTest`).

### Testes no MySQL (`composer test:mysql`)

O SQLite em memória não trava linha, então as travas da agenda (dois agendamentos no mesmo horário ao mesmo tempo) só valem no MySQL. O `composer test:mysql` roda a suíte inteira com o `phpunit.mysql.xml`, que lê `DB_HOST`, `DB_PORT`, `DB_DATABASE` (padrão `barberflow_testing`), `DB_USERNAME` e `DB_PASSWORD` do ambiente. Só nele roda o `AppointmentConcurrencyTest`, que abre duas conexões como dois pedidos ao mesmo tempo e confere as travas em passos de ordem fixa. A prova com pedidos simultâneos de verdade (vários processos) fica para o teste de carga `concorrencia.js`.

As regras da agenda que os testes supõem (expediente, limites e janela de cancelamento) ficam fixas nos padrões pelo `tests/TestCase.php`, então o `.env` montado junto com o projeto não muda o resultado.

> **Atenção:** os testes apagam e recriam o banco. Use só um MySQL descartável, nunca o banco de desenvolvimento ou da homologação. O `tests/TestCase.php` recusa banco sem "test" no nome.

No Windows sem PHP (caminho 2), com um MySQL 8.4 e um PHP 8.4 temporários, que somem no fim (a imagem `composer:2` não tem `pdo_mysql`). No PowerShell, na raiz do projeto:

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

Com PHP e MySQL na máquina (caminho 1), crie um banco de teste vazio (ex.: `barberflow_testing`) com um usuário só para ele, defina `DB_USERNAME` e `DB_PASSWORD` no ambiente do terminal e rode `composer test:mysql`.

## Endpoints

Base URL: `http://localhost:8000/api`. Todas as rotas pedem o token Bearer do login, menos login, cadastro de conta, esqueci a senha, redefinição e health. O que cada perfil pode fazer está em [`docs/API.md`](docs/API.md#perfis-e-permissões).

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
| Clients | GET | `/clients` | Lista os clientes, paginado (`search`, `per_page`, `page`) |
| Clients | POST | `/clients` | Cadastra um cliente |
| Clients | GET | `/clients/{id}` | Mostra um cliente |
| Clients | PUT/PATCH | `/clients/{id}` | Atualiza um cliente |
| Clients | DELETE | `/clients/{id}` | Exclui um cliente e apaga os dados pessoais dele (LGPD) |
| Barbers | GET | `/barbers` | Lista os barbeiros, paginado (`search`, `per_page`, `page`) |
| Barbers | POST | `/barbers` | Cadastra um barbeiro |
| Barbers | GET | `/barbers/{id}` | Mostra um barbeiro |
| Barbers | PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro |
| Barbers | DELETE | `/barbers/{id}` | Exclui um barbeiro (mantém o nome, libera o e-mail e apaga o telefone) |
| Barbers | GET | `/barbers/{id}/available-slots` | Horários livres do barbeiro no dia para um serviço (`date`, `service_id`) |
| Appointments | GET | `/appointments` | Lista os agendamentos em ordem de data e horário (`date`, `from`, `to`, `barber_id`, `client_id`, `status`, `per_page`, `page`) |
| Appointments | POST | `/appointments` | Agenda, com o término e o preço calculados pelo servidor |
| Appointments | GET | `/appointments/{id}` | Mostra um agendamento |
| Appointments | PUT/PATCH | `/appointments/{id}` | Remarca (data, horário, barbeiro ou serviço) e muda as observações |
| Appointments | PATCH | `/appointments/{id}/status` | Muda o status (confirmar, iniciar, concluir, cancelar, faltou) |

Excluir cliente, barbeiro ou serviço com agendamento futuro ainda ativo dá `409`, e a tela mostra a mensagem da API.

Corpos de requisição, regras de validação, códigos de resposta e exemplos completos estão em [`docs/API.md`](docs/API.md). Há também uma coleção do Postman em [`docs/BarberFlow.postman_collection.json`](docs/BarberFlow.postman_collection.json) (importe no Postman ou no Insomnia).

## Estrutura do projeto

```
app/
  Enums/              # UserRole (perfis) e AppointmentStatus (status e transições da agenda)
  Exceptions/         # 429 do login bloqueado (TooManyLoginAttemptsException)
  Http/Controllers/   # ServiceController, ClientController, BarberController, AppointmentController,
                      # AvailableSlotController (horários livres) e AuthController (login da API)
    Auth/             # login, cadastro, confirmação de e-mail e senha pelas telas
  Http/Middleware/    # SecurityHeaders e EnsureEmailIsVerified
  Listeners/          # liga a conta ao cadastro de cliente quando o e-mail é confirmado
  Models/             # Service, Client, Barber, Appointment, User
    Concerns/         # ReleasesEmailOnDelete e HasAppointments (409 ao excluir com agendamento futuro)
  Policies/           # quem pode o quê em cada recurso
  Rules/              # regras de validação próprias (NotOnlySpaces)
  Services/           # AppointmentScheduler (regras da agenda) e Auth/ (login, cadastro e senha)
config/barberflow.php # limites, segurança, admin inicial e expediente, lidos do .env
database/
  migrations/         # estrutura das tabelas
  seeders/            # DatabaseSeeder (usuário de teste), AdminUserSeeder e ServiceSeeder
resources/
  views/              # telas em Blade (layouts, auth, account, appointments, errors e cadastros)
  js/pages.js         # comportamento das telas e chamadas à API
  css/pages.css       # estilos próprios (a CSP não deixa usar style="")
routes/
  api.php             # rotas da API (Route::apiResource), com nomes api.*
  web.php             # rotas das telas
tests/
  Feature/            # testes dos endpoints, da agenda, da autenticação, das telas e do seed
phpunit.xml           # testes no SQLite em memória (composer test)
phpunit.mysql.xml     # a mesma suíte num MySQL descartável (composer test:mysql)
docs/
  API.md              # referência da API
  ARQUITETURA.md      # arquitetura, modelo de dados e roadmap
```

## Status do projeto

- [x] Ambiente configurado (Laravel + MySQL)
- [x] Migrations: `users`, `barbers`, `clients`, `services`
- [x] CRUD de `services`
- [x] CRUD de `clients`
- [x] CRUD de `barbers`
- [x] Testes de feature dos três recursos
- [x] Paginação e busca nas listagens
- [x] Exclusão lógica (soft delete), com anonimização do cliente (LGPD)
- [x] Mensagens em português do Brasil e fuso de São Paulo
- [x] `GET /api/health` (banco e cache)
- [x] Autenticação e permissões por perfil (Sanctum)
- [x] Agendamentos: agenda do dia por barbeiro, Agendar da barbearia e do cliente, Meus horários
- [x] Horários livres (`GET /api/barbers/{id}/available-slots`)
- [x] Sem sobreposição de horário por barbeiro e por cliente, com trava contra dois pedidos ao mesmo tempo (conferida no MySQL pelo `AppointmentConcurrencyTest`, com `composer test:mysql`; a prova com pedidos simultâneos de verdade fica para o teste de carga `concorrencia.js`)
- [x] Limites do agendamento: até 60 dias à frente e até 3 agendamentos ativos por cliente (configuráveis)
- [x] Status do atendimento (agendado, confirmado, em atendimento, concluído, cancelado e faltou) e cancelamento pelo cliente até 2 horas antes
- [ ] Pagamentos
- [ ] Dashboard básico
- [ ] Lembretes e fidelidade

## Limitações conhecidas

- O usuário de teste `test@example.com` tem uma senha conhecida, que está no `DatabaseSeeder`. Serve só para o ambiente local: em produção, o admin vem do `AdminUserSeeder` e o seed de teste nunca roda.
- O cadastro de conta avisa quando o e-mail já tem conta ("Este e-mail já está cadastrado."). O login e o "esqueci minha senha" não revelam isso.
- As listagens devolvem no máximo 100 itens por página. A tela Agendar carrega os 100 primeiros clientes, barbeiros e serviços ativos em ordem de nome. A Agenda e Meus horários leem até 1000 agendamentos.
- Os horários livres olham só a agenda do barbeiro. Se o cliente já tem horário com outro barbeiro no mesmo período, o Agendar recusa ao salvar, com a mensagem no campo de cliente.
- No SQLite local, um pedido grava por vez: o `config/database.php` usa transações `IMMEDIATE` e espera até 5 segundos pela vez (`busy_timeout`), em vez de responder "database is locked" quando as telas fazem várias chamadas ao mesmo tempo.

## Documentação

- [Referência da API](docs/API.md)
- [Arquitetura, modelo de dados e roadmap](docs/ARQUITETURA.md)
