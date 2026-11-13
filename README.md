# BarberFlow

Sistema de gestão e agendamento para barbearias, desenvolvido como projeto da disciplina **Arquitetura de Microsserviços e Desenvolvimento de APIs**.

**Equipe:** Eduardo Romano, Jose Coene, Leonam e Manoel

O objetivo do produto é ajudar a barbearia a reduzir horários vazios e faltas, aumentar a recorrência de clientes e acompanhar o faturamento. Esta primeira versão (MVP em construção) expõe uma **API REST** para os cadastros-base do sistema.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend / API | Laravel 13 (PHP 8.4.1+, testado em PHP 8.5) |
| Banco de dados | MySQL |
| ORM | Eloquent |
| Autenticação (planejada) | Laravel Sanctum |
| Frontend | Telas em Blade + Bootstrap 5.3 via Vite (cadastros ligados à API; login, agenda e agendamento ainda só visuais) |

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
   O seed cria os 4 serviços de exemplo e o usuário de teste `test@example.com`. Pode rodar de novo sem duplicar nada, mas os 4 serviços de exemplo voltam ao preço, à duração e ao status padrão: o que você mudou neles pela tela se perde. Os outros cadastros não mudam.
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
   O `127.0.0.1` no `-p` abre a porta só para a sua máquina. Com `-p 8000:8000`, o Docker escuta em todas as interfaces e qualquer pessoa na mesma rede (o Wi-Fi da faculdade, por exemplo) alcança o app: como a API ainda não pede login, ela poderia ler, editar e apagar os cadastros. O `--host=0.0.0.0` vale só dentro do container e precisa ficar: sem ele, o Docker não alcança o servidor. O `PHP_CLI_SERVER_WORKERS=4` deixa o servidor atender várias requisições ao mesmo tempo e só funciona junto com o `--no-reload`.
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

- Telas: a Agenda fica em `http://localhost:8000/appointments` e o menu leva aos cadastros. A tela de login fica em `/login`, mas o login ainda não funciona.
- API: `http://localhost:8000/api`. Teste rapidamente:
  ```bash
  curl http://localhost:8000/api/services
  ```

> **Erro 500 "Vite manifest not found":** as telas carregam o CSS e o JS pelo Vite. Se o `npm run build` não rodou e o `npm run dev` não está aberto, as telas respondem 500 com essa mensagem. Rode um dos dois. A API não depende disso.

> **Atenção:** `clients` e `barbers` começam vazios: o seed cria só os serviços e o usuário de teste.
> Se você já tinha rodado as migrations antes de atualizar o projeto, recrie o banco com `php artisan migrate:fresh` (isso apaga os dados).

### Atualizando um ambiente que já existia

Quem já tinha o projeto rodando antes da paginação e da exclusão lógica precisa de dois ajustes:

1. Rodar `php artisan migrate` (no caminho 2, `docker exec -w /app barberflow-app php artisan migrate`). Ele só acrescenta a coluna `deleted_at` e não apaga nada.
2. No `.env`, trocar `APP_LOCALE=en` por `APP_LOCALE=pt_BR` e acrescentar `APP_TIMEZONE=America/Sao_Paulo`. Sem isso, as mensagens da API continuam em inglês. Depois reinicie o servidor (no caminho 2, `docker restart barberflow-app`).

## Testes

```bash
composer test
```

No caminho 2, rode `docker exec -w /app barberflow-app composer test`.

O `composer test` limpa o cache de configuração antes de rodar o `php artisan test`. Com a configuração em cache, os testes ignorariam o `phpunit.xml` e usariam o banco do `.env`, apagando os dados dele.

Os testes de feature em `tests/Feature` cobrem:

- o CRUD de `services`, `clients` e `barbers`: listagem paginada (`data`, `links` e `meta`), busca, `page` e `per_page` inválidos, criação, limites de cada campo, consulta, atualização, exclusão lógica (com a anonimização do cliente e a troca do e-mail e do telefone do barbeiro, mesmo quando o endereço reservado já está ocupado), recadastro do e-mail de quem foi excluído e 404;
- os erros da API em português (`ApiErrorsTest`: 404 de registro e de rota, 405 e 422);
- o idioma e o fuso (`LocalizationTest`);
- o `GET /api/health` com tudo no ar (200), com o banco ou o cache fora (503) e fora do limite de requisições (`HealthApiTest`);
- as telas (`WebPagesTest`: cada tela responde, o menu aponta para as telas e não para a API, e nenhum nome de rota se repete);
- o seed (`DatabaseSeederTest`).

## Endpoints

Base URL: `http://localhost:8000/api`

| Recurso | Método | Rota | Descrição |
|---|---|---|---|
| Health | GET | `/health` | Confere banco e cache (200 ou 503) |
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

Corpos de requisição, regras de validação, códigos de resposta e exemplos completos estão em [`docs/API.md`](docs/API.md). Há também uma coleção do Postman em [`docs/BarberFlow.postman_collection.json`](docs/BarberFlow.postman_collection.json) (importe no Postman ou no Insomnia).

## Estrutura do projeto

```
app/
  Http/Controllers/   # ServiceController, ClientController, BarberController
  Models/             # Service, Client, Barber, User
database/
  migrations/         # estrutura das tabelas
  seeders/            # DatabaseSeeder (usuário de teste) e ServiceSeeder
resources/
  views/              # telas em Blade
  js/pages.js         # comportamento das telas e chamadas à API
routes/
  api.php             # rotas da API (Route::apiResource), com nomes api.*
  web.php             # rotas das telas
tests/
  Feature/            # testes dos endpoints, das telas e do seed
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
- [ ] Autenticação e permissões por perfil (Sanctum)
- [ ] Agenda e agendamento online
- [ ] Status do atendimento e pagamentos
- [ ] Dashboard básico
- [ ] Lembretes e fidelidade

## Limitações conhecidas

- Os endpoints atuais **não exigem autenticação**. O Sanctum está instalado, mas ainda não há rotas de login/logout nem o trait `HasApiTokens` no model `User`.
- `barbers` e `clients` ainda não têm relacionamento com `users` nem com agendamentos.
- As listagens devolvem no máximo 100 itens por página. A tela Agendar carrega os 100 primeiros clientes, barbeiros e serviços ativos em ordem de nome.

## Documentação

- [Referência da API](docs/API.md)
- [Arquitetura, modelo de dados e roadmap](docs/ARQUITETURA.md)
