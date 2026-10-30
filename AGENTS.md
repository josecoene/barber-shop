# BarberFlow

API REST para gestão e agendamento de barbearias. Projeto da disciplina Arquitetura de Microsserviços e Desenvolvimento de APIs, feito por uma equipe de 4 pessoas em ambiente Windows.

## Stack

- Laravel 13, PHP 8.3+ (testado em 8.5), MySQL, Eloquent, PHPUnit 12
- Laravel Sanctum está instalado, mas **ainda não ativado**: não há login/logout e o model `User` não tem `HasApiTokens`
- Não há frontend: o projeto expõe apenas a API em `/api`

## Comandos

- Instalar dependências: `composer install`
- Servidor: `php artisan serve` (API em http://localhost:8000/api)
- Migrations: `php artisan migrate` (recriar tudo: `php artisan migrate:fresh`, que **apaga os dados**)
- Seed de serviços: `php artisan db:seed --class=ServiceSeeder`
- Testes: `php artisan test` (usam SQLite em memória, conforme `phpunit.xml`; exigem as extensões `pdo_sqlite` e `sqlite3` ativas no php.ini)
- Formatação: `vendor/bin/pint`

## Estrutura e padrões

- Um controller por recurso em `app/Http/Controllers` (`ServiceController`, `ClientController`, `BarberController`) e um model em `app/Models` com `$fillable`
- Rotas apenas em `routes/api.php`, com `Route::apiResource(...)`
- Validação com `$request->validate()` dentro do controller. No update, usar `sometimes|required`; para e-mail único usar `unique:tabela,email,{id}` para ignorar o próprio registro
- Usar route model binding (`Service $service`) em show, update e destroy
- Respostas em JSON: 201 ao criar, 204 ao remover; 404 e 422 vêm do Laravel
- Tabelas de domínio:
  - `services`: name, duration (minutos), price (decimal 8,2), status (`active` ou `inactive`)
  - `clients` e `barbers`: name, email (único), phone (opcional, até 20 caracteres)
- Código (classes, variáveis, rotas) em inglês; README, docs e mensagens de commit em português

## Ao implementar algo novo

1. Criar uma migration nova (nunca editar migration que já está na `main`), o model, o controller e a rota
2. Escrever testes em `tests/Feature/<Recurso>ApiTest.php` com `RefreshDatabase`, criando dados com `Model::create` (os models não têm factories)
3. Atualizar `docs/API.md` e `docs/BarberFlow.postman_collection.json`
4. Atualizar o checklist de status do `README.md` e o plano em `docs/ARQUITETURA.md`
5. Rodar `php artisan test` e informar o resultado real antes de considerar a tarefa concluída

## Próximos passos (detalhes em docs/ARQUITETURA.md)

Autenticação por perfil com Sanctum, appointments (agenda, horários livres e sem sobreposição de horários por barbeiro), payments e dashboard básico.

## Regras

- Não commitar nem exibir `.env`, senhas ou tokens
- Não adicionar dependências (`composer require`) nem rodar scripts remotos (`curl | bash`) sem aprovação. Não instalar o Laravel Boost sem o grupo combinar antes
- Perguntar antes de rodar comandos que apagam dados (`migrate:fresh`, `db:wipe`)
- Trabalhar em uma branch por tarefa (`feat/<nome>`) e não commitar direto na `main`
- `routes/api.php` é o arquivo que mais gera conflito de merge: manter mudanças pequenas nele