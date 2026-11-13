# BarberFlow

API REST para gestão e agendamento de barbearias. Projeto da disciplina Arquitetura de Microsserviços e Desenvolvimento de APIs, feito por uma equipe de 4 pessoas em ambiente Windows.

## Stack

- Laravel 13, PHP 8.4.1+ (testado em 8.5), MySQL, Eloquent, PHPUnit 12
- Laravel Sanctum está instalado, mas **ainda não ativado**: não há login/logout e o model `User` não tem `HasApiTokens`
- Front: telas Blade + Bootstrap 5.3 via Vite, em `resources/views` e `resources/js/pages.js`. A API fica em `/api`

## Comandos

- Instalar dependências: `composer install` e `npm install`
- Servidor: `php artisan serve` (telas em http://localhost:8000 e API em http://localhost:8000/api)
- Front: `npm run build` (gera `public/build`) ou `npm run dev` (Vite com recarga). Sem um dos dois, as telas dão 500 "Vite manifest not found"
- Migrations: `php artisan migrate` (recriar tudo: `php artisan migrate:fresh`, que **apaga os dados**)
- Seed: `php artisan db:seed` (serviços e usuário de teste `test@example.com`; pode repetir sem duplicar, mas o `updateOrCreate` devolve os 4 serviços de exemplo ao preço, à duração e ao status padrão). Só os serviços: `--class=ServiceSeeder`
- Testes: `composer test` (limpa o cache de config e roda `php artisan test`; usam SQLite em memória, forçado no `phpunit.xml`; exigem as extensões `pdo_sqlite` e `sqlite3` ativas no php.ini)
- Formatação: `vendor/bin/pint`

## Estrutura e padrões

- Um controller por recurso em `app/Http/Controllers` (`ServiceController`, `ClientController`, `BarberController`) e um model em `app/Models` com `$fillable`
- API em `routes/api.php`, com `Route::apiResource(...)` e nomes prefixados `api.` (registrada no `then:` do `withRouting` em `bootstrap/app.php`). Rota da API sem `->name()` ganha o nome `api.`, e duas assim repetem o nome (o `WebPagesTest` acusa). Telas em `routes/web.php`
- Validação com `$request->validate()` dentro do controller. No update, usar `sometimes|required`; para e-mail único usar `unique:tabela,email,{id}` para ignorar o próprio registro
- Usar route model binding (`Service $service`) em show, update e destroy
- Respostas em JSON: 201 ao criar, 204 ao remover; 404 e 422 vêm do Laravel
- Tabelas de domínio:
  - `services`: name, duration (minutos), price (decimal 8,2), status (`active` ou `inactive`)
  - `clients` e `barbers`: name, email (único), phone (opcional, até 20 caracteres)
- Código (classes, variáveis, rotas) em inglês; README, docs e mensagens de commit em português

## Ao implementar algo novo

1. Criar uma migration nova (nunca editar migration que já está na `main`), o model, o controller e a rota
2. Escrever os testes em `tests/Feature`, criando dados com `Model::create` (`Barber` e `Client` têm `HasFactory`, mas não existe factory para eles) e usando `RefreshDatabase` sempre que o teste tocar o banco:
   - recurso da API: `<Recurso>ApiTest.php` (ex.: `ServiceApiTest`);
   - telas: `WebPagesTest.php`, com `withoutVite()`, que fica só com "cada tela responde", o menu e os nomes de rota;
   - assunto que não é um recurso: `<Assunto>Test.php` (ex.: `DatabaseSeederTest`);
   - autenticação e permissões, quando entrarem (hoje o Sanctum não está ativo): um arquivo por assunto (`AuthApiTest` para login e token da API, `WebAuthTest` para o login pela tela, `AuthorizationTest`, `RegistrationTest`, `PasswordResetTest`, `SecurityHeadersTest`, `RateLimitTest`), login nos testes pelos helpers de perfil do `tests/TestCase.php` (`actingAsAdmin()`, `actingAsBarber()` e `actingAsClient()`) e, em toda rota protegida, 401 sem login e 403 para cada perfil sem permissão (admin, barbeiro e cliente)
3. Atualizar `docs/API.md` e `docs/BarberFlow.postman_collection.json`
4. Atualizar o checklist de status do `README.md` e o plano em `docs/ARQUITETURA.md`
5. Rodar `composer test` e informar o resultado real antes de considerar a tarefa concluída

## Próximos passos (detalhes em docs/ARQUITETURA.md)

Autenticação por perfil com Sanctum, appointments (agenda, horários livres e sem sobreposição de horários por barbeiro), payments e dashboard básico.

## Regras

- Não commitar nem exibir `.env`, senhas ou tokens
- Não adicionar dependências (`composer require`) nem rodar scripts remotos (`curl | bash`) sem aprovação. Não instalar o Laravel Boost sem o grupo combinar antes
- Perguntar antes de rodar comandos que apagam dados (`migrate:fresh`, `db:wipe`)
- Trabalhar em uma branch por tarefa (`feat/<nome>`) e não commitar direto na `main`
- `routes/api.php` é o arquivo que mais gera conflito de merge: manter mudanças pequenas nele