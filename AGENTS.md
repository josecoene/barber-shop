# BarberFlow

API REST para gestão e agendamento de barbearias. Projeto da disciplina Arquitetura de Microsserviços e Desenvolvimento de APIs, feito por uma equipe de 4 pessoas em ambiente Windows.

## Stack

- Laravel 13, PHP 8.4.1+ (testado em 8.5), MySQL, Eloquent, PHPUnit 12
- Laravel Sanctum ativo: as telas entram por sessão (cookie + CSRF, `statefulApi()` no `bootstrap/app.php`) e a API por token Bearer (`POST /api/login`, prefixo `barberflow_`, validade `SANCTUM_EXPIRATION`). O `User` tem `HasApiTokens` e `MustVerifyEmail`
- Perfis em `users.role` (enum `App\Enums\UserRole`: `admin`, `barber`, `client`). O `role` nunca entra no `$fillable`: grave com `forceFill`, para ninguém virar admin por mass assignment
- Front: telas Blade + Bootstrap 5.3 via Vite, em `resources/views` (layouts `app` e `guest`), `resources/js/pages.js` e `resources/css/pages.css`. A API fica em `/api`

## Comandos

- Instalar dependências: `composer install` e `npm install`
- Servidor: `php artisan serve` (telas em http://localhost:8000 e API em http://localhost:8000/api)
- Front: `npm run build` (gera `public/build`) ou `npm run dev` (Vite com recarga). Sem um dos dois, as telas dão 500 "Vite manifest not found"
- Migrations: `php artisan migrate` (recriar tudo: `php artisan migrate:fresh`, que **apaga os dados**)
- Seed: `php artisan db:seed` (serviços e usuário de teste `test@example.com` com perfil admin, sem mudar a senha dele, que está no `DatabaseSeeder`; pode repetir sem duplicar, mas o `updateOrCreate` devolve os 4 serviços de exemplo ao preço, à duração e ao status padrão). Só os serviços: `--class=ServiceSeeder`. Admin de produção: `--class=AdminUserSeeder`, com `ADMIN_*` no `.env`
- Login no local: `test@example.com` nas telas (`/login`) ou no `POST /api/login`. E-mails (confirmação de conta e "esqueci a senha") vão para o `storage/logs/laravel.log` com `MAIL_MAILER=log`
- Testes: `composer test` (limpa o cache de config e roda `php artisan test`; usam SQLite em memória, forçado no `phpunit.xml`; exigem as extensões `pdo_sqlite` e `sqlite3` ativas no php.ini)
- Testes no MySQL: `composer test:mysql` roda a mesma suíte com o `phpunit.mysql.xml` (banco `barberflow_testing`; `DB_HOST`, `DB_PORT`, `DB_USERNAME` e `DB_PASSWORD` vêm do ambiente, sem `force`). Só nele roda o `AppointmentConcurrencyTest`, que confere as travas da agenda com duas conexões abertas ao mesmo tempo (no `composer test` ele aparece como pulado); a prova com pedidos simultâneos de verdade fica para o teste de carga `concorrencia.js`. **Só num MySQL descartável**: o `RefreshDatabase` apaga o banco, e o `tests/TestCase.php` recusa rodar em banco que não seja o SQLite em memória ou que não tenha "test" no nome (receita com Docker no README)
- Formatação: `vendor/bin/pint`

## Estrutura e padrões

- Um controller por recurso em `app/Http/Controllers` (`ServiceController`, `ClientController`, `BarberController`, `AppointmentController` e o `AvailableSlotController` dos horários livres) e um model em `app/Models` com `$fillable`. Autenticação: `AuthController` (API) e `Auth\*Controller` (telas), com as regras comuns em `app/Services/Auth`
- Agenda: as regras (término calculado, expediente, fatias, nada no passado nem depois do limite de dias, sem sobreposição por barbeiro e por cliente, limite de agendamentos ativos do cliente, transições e janela de cancelamento) ficam no `App\Services\AppointmentScheduler`, e os status e as transições só no enum `App\Enums\AppointmentStatus` (as telas recebem tudo pelo `AppointmentStatus::catalog()`). Agendar e remarcar travam as linhas sempre na ordem barbeiro, cliente, serviço e, na remarcação, o agendamento. As consultas sem trava (sobreposição e limite do cliente) vêm depois de todas as travas e ficam sem `lockForUpdate` de propósito: no MySQL ele trava o intervalo do índice e dá deadlock entre agendamentos sem conflito, e a foto do banco nasce na primeira leitura sem trava, então nenhuma leitura sem trava pode entrar antes das travas. Mexeu nisso, rode `composer test:mysql` (`AppointmentConcurrencyTest`). O "agora" é sempre o `now()` do PHP, nunca o relógio do banco
- API em `routes/api.php`, com `Route::apiResource(...)` e nomes prefixados `api.` (registrada no `then:` do `withRouting` em `bootstrap/app.php`). **Toda rota nova da API recebe `->name()`**: sem nome, ela ganha o nome `api.`, e duas assim repetem o nome (o `WebPagesTest` acusa). **Toda rota nova da API entra no grupo `verified`**, dentro do `auth:sanctum` (o `AuthorizationTest` confere); fora do `verified` ficam só as da própria conta (`/user`, `/user/password`, `/logout` e o reenvio da confirmação), e só login, cadastro de conta, "esqueci a senha", redefinição e `/health` ficam abertas. Telas em `routes/web.php`, dentro de `auth` e protegidas por `can:` com os Gates `access-admin`, `access-staff` e `access-client` (as do cliente, `/booking` e `/my-appointments`, também com `verified`)
- Permissões: uma Policy por recurso em `app/Policies` (descoberta automática pelo nome do model), com `before()` liberando o admin. O controller chama `Gate::authorize('ação', $model)` no começo de cada método. Regra por campo também vira método da Policy (ex.: `BarberPolicy::viewContactDetails`). Recurso novo ganha Policy e entra na matriz de perfis de `docs/API.md`
- Configuração: tudo que vem do ambiente passa por `config/barberflow.php` (limites, segurança, admin inicial, expediente em `BUSINESS_OPENING`, `BUSINESS_CLOSING`, `BUSINESS_SLOT_MINUTES` e `BUSINESS_DAYS`, a janela de cancelamento do cliente em `CLIENT_CANCEL_MIN_HOURS`, o limite de agendamentos ativos do cliente em `CLIENT_MAX_ACTIVE_APPOINTMENTS` e até quantos dias à frente dá para agendar em `BOOKING_DAYS_AHEAD`), nunca `env()` fora de `config/`, porque o `php artisan optimize` congela a configuração. Variável nova também vai para o `.env.example`
- Telas: o `apiRequest` do `pages.js` já manda `X-CSRF-TOKEN` e `X-Requested-With`, leva ao login em 401 e 419 e mostra a mensagem de permissão em 403. Nada de `style=""` nem `<script>` no HTML: a CSP bloqueia (posição e altura calculadas, como os blocos da Agenda, vão por `el.style` no JS, que a CSP permite). Classe própria vai em `resources/css/pages.css`. Formulário que vai direto ao servidor usa `@csrf` e o parcial `partials.form-field`. Valor de configuração que a tela precisa (expediente, janela de cancelamento, regras de status) vai por data-attribute na view, e "hoje" e "agora" são calculados no fuso da meta `app-timezone` do layout
- Validação com `$request->validate()` dentro do controller. No update, usar `sometimes|required`; para e-mail único usar `unique:tabela,email,{id}` para ignorar o próprio registro
- Usar route model binding (`Service $service`) em show, update e destroy
- Respostas em JSON, no mesmo estilo nos controllers: `store` devolve `response()->json($model, 201)`, `show` e `update` devolvem o model e `destroy` devolve `response()->noContent()` (204). A agenda não tem `destroy` (`apiResource(...)->except('destroy')`): cancelar é `PATCH /api/appointments/{id}/status`
  - `index` valida `page` (1 a 100000, teto que evita estourar a conta do offset), `per_page` (1 a 100, padrão 15) e `search` (até 100 caracteres) com `$request->validate()` e devolve `JsonResource::collection(...->orderBy('name')->orderBy('id')->paginate()->withQueryString())`: resposta com `data` (itens), `links` (`first`, `last`, `prev`, `next`) e `meta` (`current_page`, `last_page`, `per_page`, `total` etc.), sem classe de Resource própria. O `index` da agenda é diferente: sem `search`, em ordem de `date`, `start_time` e `id`, com os filtros `date`, `from`, `to`, `barber_id`, `client_id` e `status`, e o scope `visibleTo` limita o barbeiro e o cliente aos deles
  - 409 sai como `{"message": "..."}` com a mensagem de quem recusou (`ConflictHttpException`): excluir cliente, barbeiro ou serviço com agendamento futuro ativo, ou remarcação que mudou no meio do caminho
  - 422 vem do Laravel, com as mensagens de `lang/pt_BR/validation.php`. Campo novo ganha o nome em português no bloco `attributes` desse arquivo
  - 404 sai como `{"message": "Registro não encontrado."}` (route model binding) ou `{"message": "Rota não encontrada."}`, e 405 como `{"message": "Método não permitido para esta rota."}`, pelo `withExceptions` do `bootstrap/app.php`
  - `GET /api/health` (`HealthController`) confere banco e cache: 200 ou 503, só com `ok`/`falhou`, e fica fora do limite de requisições com `withoutMiddleware('throttle:api')` (se o `throttleApi()` usar outro limitador, troque também ali). O `/up` continua raso, porque é o health check do balanceador
- Tabelas de domínio (`services`, `clients` e `barbers` com `SoftDeletes`: o `DELETE` preenche `deleted_at` e a linha fica para o histórico):
  - `services`: name (até 255), duration (minutos, de 5 a 480, cast `integer`), price (decimal 8,2, até 999999.99, cast `decimal:2`, que sai no JSON como texto `"35.00"`), status (`active` ou `inactive`; opcional no POST, com padrão `active` no `$attributes` do model)
  - `appointments` (sem `SoftDeletes`: cancelar é status): `client_id`, `barber_id` e `service_id` (FK `restrict`; as relações usam `withTrashed()`), `date` (sempre `Y-m-d`), `start_time` e `end_time` (sempre `H:i:s`; a API aceita `H:i`; o término vem do servidor), `status` (enum `AppointmentStatus`: `scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled`, `no_show`), `price` (cópia do preço do serviço, `decimal:2`) e `notes` (até 1000). O JSON traz `status_label` e `client`, `barber` e `service` só com `id` e `name` (`Appointment::SUMMARY_RELATIONS` + `summarizeRelations()`)
  - Excluir cliente, barbeiro ou serviço com agendamento de hoje em diante ainda ativo dá 409: o `destroy` chama `ensureNoUpcomingAppointments()` (trait `App\Models\Concerns\HasAppointments`) dentro do `DB::transaction` da exclusão
  - `clients` e `barbers`: name (até 255), email (único, até 255), phone (opcional, até 20 caracteres) e `user_id` (login ligado: nullable, unique, `nullOnDelete`). O `user_id` e a relação `user` ficam escondidos no JSON (`$hidden`), e o `has_login` (appends) diz se há login. O e-mail do barbeiro também não pode ser de outro usuário, porque vira o login dele (`Barber::syncLogin`)
  - `users`: `role` (sem padrão) e `phone` (o telefone do cadastro de conta, até a confirmação do e-mail)
  - Excluir cliente anonimiza (LGPD) com `Client::anonymizeAndDelete()`: nome "Cliente removido", e-mail `removido+{id}@barberflow.invalid`, telefone nulo e as observações (`notes`) dos agendamentos dele apagadas, porque são texto livre e podem ter dado pessoal. Excluir barbeiro mantém só o nome, com `Barber::releaseEmailAndDelete()`: troca o e-mail e apaga o telefone. Os dois liberam o e-mail original no `unique` do banco; nunca chame `delete()` direto nesses models. Por isso a validação de e-mail de clientes e barbeiros recusa o domínio `barberflow.invalid` (`not_regex`). O endereço vem do trait `App\Models\Concerns\ReleasesEmailOnDelete`, que pergunta ao banco se ele já está em uso (o MySQL iguala `barberflow.ínvalid` a `barberflow.invalid`) e, se estiver, acrescenta um sufixo aleatório
- Código (classes, variáveis, rotas) em inglês; README, docs e mensagens de commit em português

## Ao implementar algo novo

1. Criar uma migration nova (nunca editar migration que já está na `main`), o model, o controller e a rota
2. Escrever os testes em `tests/Feature`, criando dados com `Model::create` (`Barber` e `Client` têm `HasFactory`, mas não existe factory para eles) e usando `RefreshDatabase` sempre que o teste tocar o banco:
   - recurso da API: `<Recurso>ApiTest.php` (ex.: `ServiceApiTest`, `AppointmentApiTest`), com as permissões de um recurso grande num arquivo à parte (ex.: `AppointmentAuthorizationTest`). Regra que depende da hora fixa o relógio com `travelTo`;
   - telas: `WebPagesTest.php`, com `withoutVite()`, para "cada tela responde", o layout, o menu de cada perfil, as páginas de erro e os nomes de rota. Todo teste que abre uma tela (inclusive página de erro) chama `withoutVite()`, para não depender do `npm run build`;
   - assunto que não é um recurso: `<Assunto>Test.php` (ex.: `DatabaseSeederTest`);
   - autenticação e permissões: um arquivo por assunto (`AuthApiTest` para login e token da API, `WebAuthTest` para o login pela tela e quem abre cada tela, `AuthorizationTest`, `RegistrationTest`, `PasswordResetTest`, `SecurityHeadersTest`, `RateLimitTest`);
   - login nos testes pelos helpers do `tests/TestCase.php`: `actingAsAdmin()`, `actingAsBarber()` e `actingAsClient()` para a API (`Sanctum::actingAs`), e `makeAdmin()`, `makeBarberUser()` e `makeClientUser()` (todos com a senha `self::PASSWORD`) com `actingAs()` para as telas. Para trocar de usuário no meio de um teste de tela, chame `flushSession()` antes: o `auth.session` derruba a sessão do usuário anterior;
   - em toda rota protegida, 401 sem login e 403 para cada perfil sem permissão (admin, barbeiro e cliente) e para a conta de cliente sem o e-mail confirmado. Valor de configuração muda com `config([...])`. As regras da agenda (expediente, limites e janela de cancelamento) ficam fixas nos padrões pelo `setUp()` do `tests/TestCase.php`, para o `.env` e o ambiente não mudarem o resultado da suíte
3. Atualizar `docs/API.md` e `docs/BarberFlow.postman_collection.json`
4. Atualizar o checklist de status do `README.md` e o plano em `docs/ARQUITETURA.md`
5. Rodar `composer test` e informar o resultado real antes de considerar a tarefa concluída

## Próximos passos (detalhes em docs/ARQUITETURA.md)

Payments (registro do pagamento dos atendimentos concluídos), dashboard básico, lembretes por e-mail e fidelidade, seguindo a matriz de perfis de `docs/ARQUITETURA.md`.

## Regras

- Não commitar nem exibir `.env`, senhas ou tokens
- Não adicionar dependências (`composer require`) nem rodar scripts remotos (`curl | bash`) sem aprovação. Não instalar o Laravel Boost sem o grupo combinar antes
- Perguntar antes de rodar comandos que apagam dados (`migrate:fresh`, `db:wipe`)
- Trabalhar em uma branch por tarefa (`feat/<nome>`) e não commitar direto na `main`
- `routes/api.php` é o arquivo que mais gera conflito de merge: manter mudanças pequenas nele