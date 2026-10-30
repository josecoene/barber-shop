# BarberFlow

Sistema de gestão e agendamento para barbearias, desenvolvido como projeto da disciplina **Arquitetura de Microsserviços e Desenvolvimento de APIs**.

**Equipe:** Eduardo Romano, Jose Coene, Leonam e Manoel

O objetivo do produto é ajudar a barbearia a reduzir horários vazios e faltas, aumentar a recorrência de clientes e acompanhar o faturamento. Esta primeira versão (MVP em construção) expõe uma **API REST** para os cadastros-base do sistema.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend / API | Laravel 13 (PHP 8.3+, testado em PHP 8.5) |
| Banco de dados | MySQL |
| ORM | Eloquent |
| Autenticação (planejada) | Laravel Sanctum |
| Frontend (planejado) | Blade + Bootstrap |

## Pré-requisitos

- PHP 8.3 ou superior
- Composer
- MySQL
- Extensões do PHP habilitadas no `php.ini`: `mbstring`, `fileinfo`, `curl`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`
- Para rodar os testes: `pdo_sqlite` e `sqlite3` (os testes usam SQLite em memória)

## Como rodar localmente

1. Instale as dependências:
   ```bash
   composer install
   ```
2. Crie o arquivo de ambiente (o `.env.example` já vem configurado para MySQL):
   ```bash
   cp .env.example .env        # Linux/macOS/Git Bash
   copy .env.example .env      # Windows (cmd)
   ```
3. Abra o `.env` e preencha `DB_PASSWORD` com a senha do seu MySQL.
4. Crie o banco de dados (o Laravel não cria o banco sozinho):
   ```sql
   CREATE DATABASE barberflow;
   ```
5. Gere a chave da aplicação:
   ```bash
   php artisan key:generate
   ```
6. Crie as tabelas e popule os serviços de exemplo:
   ```bash
   php artisan migrate
   php artisan db:seed --class=ServiceSeeder
   ```
7. Suba o servidor:
   ```bash
   php artisan serve
   ```

A API fica disponível em `http://localhost:8000/api`. Teste rapidamente:

```bash
curl http://localhost:8000/api/services
```

> **Atenção:** `clients` e `barbers` começam vazios. Apenas `services` possui seeder.
> Se você já tinha rodado as migrations antes de atualizar o projeto, recrie o banco com `php artisan migrate:fresh` (isso apaga os dados).

## Testes

```bash
php artisan test
```

Os testes de feature em `tests/Feature` cobrem o CRUD de `services`, `clients` e `barbers` (listagem, criação, validação, consulta, atualização, remoção e 404).

## Endpoints

Base URL: `http://localhost:8000/api`

| Recurso | Método | Rota | Descrição |
|---|---|---|---|
| Services | GET | `/services` | Lista todos os serviços |
| Services | POST | `/services` | Cria um serviço |
| Services | GET | `/services/{id}` | Mostra um serviço |
| Services | PUT/PATCH | `/services/{id}` | Atualiza um serviço |
| Services | DELETE | `/services/{id}` | Remove um serviço |
| Clients | GET | `/clients` | Lista todos os clientes |
| Clients | POST | `/clients` | Cadastra um cliente |
| Clients | GET | `/clients/{id}` | Mostra um cliente |
| Clients | PUT/PATCH | `/clients/{id}` | Atualiza um cliente |
| Clients | DELETE | `/clients/{id}` | Remove um cliente |
| Barbers | GET | `/barbers` | Lista todos os barbeiros |
| Barbers | POST | `/barbers` | Cadastra um barbeiro |
| Barbers | GET | `/barbers/{id}` | Mostra um barbeiro |
| Barbers | PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro |
| Barbers | DELETE | `/barbers/{id}` | Remove um barbeiro |

Corpos de requisição, regras de validação, códigos de resposta e exemplos completos estão em [`docs/API.md`](docs/API.md). Há também uma coleção do Postman em [`docs/BarberFlow.postman_collection.json`](docs/BarberFlow.postman_collection.json) (importe no Postman ou no Insomnia).

## Estrutura do projeto

```
app/
  Http/Controllers/   # ServiceController, ClientController, BarberController
  Models/             # Service, Client, Barber, User
database/
  migrations/         # estrutura das tabelas
  seeders/            # ServiceSeeder
routes/
  api.php             # rotas da API (Route::apiResource)
tests/
  Feature/            # testes dos endpoints
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
- [ ] Autenticação e permissões por perfil (Sanctum)
- [ ] Agenda e agendamento online
- [ ] Status do atendimento e pagamentos
- [ ] Dashboard básico
- [ ] Lembretes e fidelidade

## Limitações conhecidas

- Os endpoints atuais **não exigem autenticação**. O Sanctum está instalado, mas ainda não há rotas de login/logout nem o trait `HasApiTokens` no model `User`.
- `barbers` e `clients` ainda não têm relacionamento com `users` nem com agendamentos.
- Não existe paginação nas listagens (`GET` retorna todos os registros).

## Documentação

- [Referência da API](docs/API.md)
- [Arquitetura, modelo de dados e roadmap](docs/ARQUITETURA.md)
