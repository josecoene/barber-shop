# BarberFlow

Sistema de gestão e agendamento para barbearias — projeto da disciplina de Arquitetura de API.

## Stack

- Backend: Laravel 13 (PHP 8.5)
- Banco de dados: MySQL
- Frontend (planejado): Blade + Bootstrap

## Setup local

1. `composer install`
2. Copiar `.env.example` para `.env` e configurar `DB_DATABASE=barberflow` (MySQL)
3. `php artisan key:generate`
4. Criar o banco: `CREATE DATABASE barberflow;`
5. `php artisan migrate`
6. `php artisan db:seed --class=ServiceSeeder`
7. `php artisan serve`

## Progresso atual

- [x] Estrutura do projeto (Laravel + MySQL configurados)
- [x] Migrations: `users`, `barbers`, `clients`, `services`
- [x] Model + Controller + Seeder de `services`
- [x] API REST de `services` (CRUD completo via `Route::apiResource`)
- [x] `clients` (CRUD)
- [x] `barbers` (CRUD)
- [ ] Agenda / agendamento online
- [ ] Autenticação por perfil (Sanctum)

## Endpoints disponíveis

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/services` | Lista todos os serviços |
| POST | `/api/services` | Cria um novo serviço |
| GET | `/api/services/{id}` | Mostra um serviço específico |
| PUT/PATCH | `/api/services/{id}` | Atualiza um serviço |
| DELETE | `/api/services/{id}` | Remove um serviço |
| Método | Rota | Descrição |
| --- | --- | --- |
| GET | `/api/barbers` | Lista todos os barbeiros |
| POST | `/api/barbers` | Cadastra um novo barbeiro |
| GET | `/api/barbers/{id}` | Exibe os dados de um barbeiro específico |
| PUT/PATCH | `/api/barbers/{id}` | Atualiza os dados de um barbeiro |
| DELETE | `/api/barbers/{id}` | Remove um barbeiro |
| GET | `/api/clients` | Lista todos os clientes |
| POST | `/api/clients` | Cadastra um novo cliente |
| GET | `/api/clients/{id}` | Exibe os dados de um cliente específico |
| PUT/PATCH | `/api/clients/{id}` | Atualiza os dados de um cliente |
| DELETE | `/api/clients/{id}` | Remove um cliente |
