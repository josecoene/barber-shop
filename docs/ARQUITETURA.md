# Arquitetura do BarberFlow

Projeto da disciplina **Arquitetura de Microsserviços e Desenvolvimento de APIs**.
Equipe: Eduardo Romano, Jose Coene, Leonam e Manoel.

## 1. Visão geral

O BarberFlow é uma plataforma de gestão e agendamento para barbearias. O foco do produto é ajudar a barbearia a preencher horários vazios, reduzir faltas, aumentar a recorrência de clientes e acompanhar o faturamento.

Nesta etapa o sistema é uma **API REST monolítica modular** em Laravel: um único serviço com camadas bem separadas (rotas, controllers, models, banco). Essa escolha mantém o MVP simples e robusto, e deixa a base pronta para, no futuro, evoluir para um SaaS multiempresa.

## 2. Stack

| Camada | Tecnologia | Motivo |
|---|---|---|
| Backend / API | Laravel 13 (PHP 8.4.1+) | Roteamento de API, validação e ORM prontos; estrutura MVC alinhada ao esboço inicial |
| Banco de dados | MySQL | Banco relacional, adequado a agenda, clientes e financeiro |
| ORM | Eloquent | Evita SQL manual e facilita relacionamentos futuros |
| Autenticação | Laravel Sanctum | Sessão (cookie + CSRF) para as telas e token Bearer para a API, no mesmo pacote e com as mesmas regras de perfil |
| Telas | Blade + Bootstrap 5.3 via Vite | Telas servidas pelo próprio Laravel, que chamam a API com a sessão do navegador |
| Testes | PHPUnit (testes de feature) | Valida os endpoints de ponta a ponta |

## 3. Fluxo de uma requisição

```
Cliente HTTP (Postman, telas no navegador, app)
        │
        ▼
Middlewares               → cabeçalhos de segurança, limite de requisições (throttle:api),
                            sessão e CSRF para as telas (statefulApi) e login (auth:sanctum)
        │
        ▼
routes/api.php            → Route::apiResource(...) mapeia verbo + URL para o controller
        │
        ▼
Controller                → confere a permissão (Gate::authorize + Policy) e valida a
                            requisição ($request->validate)
        │
        ▼
Model (Eloquent)          → lê/grava no MySQL (sem as linhas excluídas)
        │
        ▼
Resposta JSON             → 200, 201, 204, 401, 403, 404, 405, 409, 422, 429 ou 503
```

Padrões adotados:

- **`Route::apiResource`**: gera as 5 rotas REST de cada recurso com uma única linha.
- **Route model binding**: os métodos `show`, `update` e `destroy` recebem o model já carregado (ex.: `Service $service`). Se o ID não existe ou foi excluído, a API responde `404` com `{"message": "Registro não encontrado."}`.
- **Validação nos controllers**: regras declaradas em `$request->validate(...)`. Falhas retornam `422` com a lista de erros por campo, em português.
- **Limites iguais aos do banco**: `name` e `email` até 255 caracteres, `phone` até 20, `duration` de 5 a 480 minutos e `price` até 999999.99 (o máximo do `decimal(8,2)`). Assim um valor grande demais vira `422`, e não um erro 500 do MySQL.
- **Mesmo estilo nos controllers**: `store` devolve `201` com o registro, `show` e `update` devolvem o registro e `destroy` devolve `204` sem corpo. A agenda e os pagamentos não têm `destroy`: cancelar é `PATCH /appointments/{id}/status` e estornar é `PATCH /payments/{id}/status`, que devolvem o registro.
- **Listagens paginadas**: `index` devolve uma página por vez, em ordem de nome, com `page` (1 a 100000), `per_page` (1 a 100, padrão 15) e `search`. Serviços aceitam também `status`. Os agendamentos saem em ordem de data e horário, sem `search`, com os filtros `date`, `from`, `to`, `barber_id`, `client_id`, `status` e `without_payment` (concluídos sem pagamento). Os pagamentos saem do atendimento mais recente para o mais antigo, com os filtros `from`, `to` (pela data do atendimento), `method`, `status`, `barber_id` e `client_id`, e trazem também `totals` (quantidade e soma de cada status com os mesmos filtros, em todas as páginas). A resposta tem `data` (itens), `links` (primeira, última, anterior e próxima página) e `meta` (página atual, última página, total etc.), montada pelo `JsonResource::collection()` do Laravel, sem classe de Resource própria. O teto do `page` existe porque a conta do offset (`(page - 1) * per_page`) estoura o inteiro do PHP com uma página enorme e viraria erro 500.
- **Respostas sempre em JSON** para qualquer rota `api/*` (configurado em `bootstrap/app.php`). Lá também ficam as mensagens de `404` (registro ou rota inexistente) e `405` (método errado) em português, sem o nome da classe do model que o Laravel colocaria na mensagem.
- **Mass assignment protegido**: cada model declara `$fillable` com os campos permitidos.
- **Casts no model**: `Service` converte `duration` para inteiro e `price` para texto com 2 casas (`"35.00"`), então o JSON sai igual no SQLite e no MySQL.

### Idioma e fuso horário

- **Português do Brasil:** `APP_LOCALE=pt_BR`. As mensagens de validação, autenticação, senha e paginação ficam em `lang/pt_BR/` e as frases soltas do framework em `lang/pt_BR.json`, escritas à mão a partir dos arquivos em inglês do Laravel. O bloco `attributes` do `validation.php` troca o nome técnico do campo pelo nome em português (`name` vira "nome"). O inglês continua como reserva (`APP_FALLBACK_LOCALE=en`).
- **Fuso de São Paulo:** `APP_TIMEZONE`, com padrão `America/Sao_Paulo` em `config/app.php`. O `now()` do PHP e as datas gravadas no banco usam a hora de Brasília, e o JSON entrega as datas em UTC (ISO 8601 com `Z`), que qualquer cliente converte. A agenda (horários de atendimento) depende desse fuso.

### Saúde da aplicação

| Rota | O que confere | Quem usa |
|---|---|---|
| `GET /up` | só que o PHP responde | o balanceador de carga e o Auto Scaling (health check do ALB) |
| `GET /api/health` | banco (`select 1`) e cache (grava e lê uma chave) | monitoramento, deploy, testes de carga e de resiliência |

O `/up` continua raso de propósito: se ele dependesse do banco, uma queda do banco faria o Auto Scaling trocar instâncias que estão boas, sem parar. O `/api/health` responde `200` com `{"status":"ok"}` ou `503` dizendo qual conferência falhou, só com `ok` ou `falhou`; o motivo vai para o log, nunca para a resposta. Ele é aberto (sem login) e fica fora do limite de requisições da API (`withoutMiddleware('throttle:api')`), para o monitoramento e o deploy não receberem `401` nem `429`.

### Autenticação: sessão nas telas, token na API

| | Telas (navegador) | API (Postman e outros sistemas) |
|---|---|---|
| Entrada | formulário `/login` (`Auth\SessionController`) | `POST /api/login` (`AuthController`) |
| Credencial | cookie da sessão + token CSRF | token Bearer com o prefixo `barberflow_` |
| Validade | a da sessão (`SESSION_LIFETIME`), ou mais com "lembrar de mim" | `SANCTUM_EXPIRATION` minutos (padrão 480). O `sanctum:prune-expired` apaga os vencidos todo dia |
| Saída | Sair (formulário `POST /logout` com `@csrf`) | `POST /api/logout`, que apaga o token da chamada |

As telas chamam a própria API com a sessão: o `statefulApi()` do `bootstrap/app.php` faz o Sanctum abrir a sessão e conferir o CSRF quando o `Referer` vem de um host de `SANCTUM_STATEFUL_DOMAINS`. O `pages.js` manda o `X-CSRF-TOKEN` (lido da meta `csrf-token` do layout) e o `Referer` (`referrerPolicy: 'same-origin'`). Assim nenhum token fica guardado no navegador, onde um script injetado poderia lê-lo. Na tela, `401` e `419` levam ao login com o aviso de sessão expirada, e `403` mostra "Você não tem permissão para esta ação.".

O login das telas e o da API seguem as mesmas regras (`App\Services\Auth\LoginAttempt`): a senha é conferida antes de abrir a sessão ou criar o token, conta sem acesso é recusada e há limite de tentativas. No login, a sessão ganha um id novo (`session()->regenerate()`), para um id roubado antes do login não servir depois.

Senha trocada encerra os outros logins: o `authenticateSessions()` guarda na sessão um resumo da senha, e a sessão cai na próxima requisição quando a senha muda. A troca de senha pela conta apaga os outros tokens; a redefinição por e-mail apaga todos os tokens, as sessões e o "lembrar de mim".

### Perfis e permissões

`users.role` guarda o perfil (`App\Enums\UserRole`): `admin`, `barber` (barbeiro) ou `client` (cliente). A coluna não tem padrão, e usuário sem perfil não entra. O `role` não está no `$fillable` do `User`: o código grava o perfil com `forceFill`, para ninguém virar admin mandando `role` numa requisição.

- **Policies** (`app/Policies`), uma por recurso, com `before()` liberando o admin. Os controllers chamam `Gate::authorize(...)`, inclusive para regras por campo (`BarberPolicy::viewContactDetails`, `ServicePolicy::viewInactive` e `ClientPolicy::changeEmail`) e por ação (`PaymentPolicy::recordFor`, para qual agendamento o barbeiro registra, `PaymentPolicy::changeStatus`, que deixa o barbeiro só marcar como pago, e `AppointmentPolicy::sendReminder`, que deixa o barbeiro mandar o lembrete só dos agendamentos dele). A fidelidade usa a mesma regra do ver cliente (`ClientPolicy::view`).
- **Gates das telas** (`AppServiceProvider`): `access-admin`, `access-staff` (admin e barbeiro) e `access-client`. As rotas de `routes/web.php` usam `can:` e o menu do layout usa `@can`. O dashboard não tem model, então a regra dele é o Gate `view-dashboard` (só o admin), que o `GET /api/dashboard` confere.
- **Login do barbeiro:** nasce quando o admin define a senha no cadastro do barbeiro e fica ligado por `barbers.user_id`. Excluir o barbeiro apaga o login, os tokens e as sessões dele.

| Recurso | Admin | Barbeiro | Cliente |
|---|---|---|---|
| Serviços | tudo | ver | ver os ativos |
| Barbeiros | tudo (o campo `password` cria ou redefine o login) | ver | ver só nome e id (sem e-mail e telefone) |
| Clientes | tudo | ver, criar, editar | só o próprio cadastro, sem listar os outros |
| Agendamentos | tudo | só os da própria agenda (ver, agendar, remarcar e mudar o status) | agendar para si (até 3 ativos, `CLIENT_MAX_ACTIVE_APPOINTMENTS`), ver os próprios, cancelar até 2h antes (`CLIENT_CANCEL_MIN_HOURS`) |
| Lembrete ("Enviar lembrete agora") | de qualquer agendamento | só dos agendamentos da própria agenda | não, nem do próprio horário |
| Pagamentos | tudo (registrar, com ou sem o prêmio da fidelidade, corrigir, marcar como pago e estornar) | registrar (com ou sem o prêmio) e ver os da própria agenda, e marcar como pago um pendente deles; não corrige nem estorna | ver os próprios |
| Fidelidade | ver a de qualquer cliente | ver a de qualquer cliente | ver a própria |
| Dashboard | ver | não | não |

Menu das telas por perfil: o admin vê Dashboard (a tela inicial dele), Agenda, Agendar, Pagamentos, Clientes, Barbeiros e Serviços; o barbeiro vê Agenda (a tela inicial dele), Agendar, Pagamentos e Clientes; o cliente vê Agendar, Meus horários (a tela inicial dele) e Minha conta. As telas do cliente (`/booking` e `/my-appointments`) usam o Gate `access-client` e exigem o e-mail confirmado; Pagamentos (`/payments`) usa o `access-staff` e o Dashboard (`/dashboard`), o `access-admin`.

### Conta de cliente

Decisão do Leonam (13/11/2026): o cliente também tem conta e vai agendar sozinho. Admin e barbeiro continuam agendando para quem chega no balcão.

1. **Cadastro** (`/register` ou `POST /api/register`): a conta nasce com o perfil `client` e o e-mail não confirmado (`MustVerifyEmail`), e o BarberFlow manda o link de confirmação.
2. **Confirmação:** o link é assinado e vale 60 minutos. Ao confirmar, o listener `LinkClientToVerifiedUser` liga a conta ao cliente que já tem o mesmo e-mail ou cria um cadastro novo, e a ligação fica em `clients.user_id`.
3. **Telas do cliente e rotas da API:** exigem o e-mail confirmado (middleware `verified`). Na API ficam de fora só as rotas da própria conta (`/user`, `/user/password`, `/logout` e o reenvio do link). As rotas da agenda ficam no mesmo grupo, e toda rota nova da API também entra nele.

A ligação só acontece depois da confirmação porque é ela que prova que a pessoa é dona do e-mail. Sem isso, qualquer um veria o histórico de outra pessoa só digitando o e-mail dela no cadastro. Pelo mesmo motivo, o cliente não muda o e-mail do próprio cadastro (nem manda o campo, mesmo vazio): um e-mail trocado para o de outra pessoa abriria caminho para tomar a conta pelo "esqueci minha senha".

O "esqueci minha senha" usa o broker de senha do Laravel (tabela `password_reset_tokens`, link de 60 minutos) e responde sempre a mesma coisa, com e-mail cadastrado ou não.

**Conta criada com o e-mail de outra pessoa.** O dono do e-mail recebe "Este e-mail já está cadastrado." no cadastro e recupera a conta pelo "esqueci minha senha". Se a conta ainda não tinha o e-mail confirmado, a redefinição descarta o nome e o telefone digitados no cadastro (o nome vira a parte do e-mail antes do `@`), porque quem os digitou pode não ser o dono do e-mail. Assim, o telefone de outra pessoa nunca chega ao cadastro de cliente na confirmação.

**Links dos e-mails:** fora do local, o `AppServiceProvider` fixa a raiz das URLs no `APP_URL` (`URL::forceRootUrl`, e `forceScheme('https')` quando o `APP_URL` é `https`). O `trustHosts` aceita os IPs privados por causa do health check do ALB. Sem a raiz fixa, quem pedisse o e-mail de outra pessoa com `Host: 192.168.0.23` faria o link levar o token para uma máquina da rede dele.

### Agenda

Decisão do Leonam (13/11/2026): a barbearia agenda para quem chega no balcão (admin, ou o barbeiro na própria agenda) e o cliente agenda sozinho pela conta dele. As regras ficam em `App\Services\AppointmentScheduler`, e os status e as transições só em `App\Enums\AppointmentStatus`.

**Regras** (as recusas são `422`, no campo do formulário):

- o término (`end_time`) é calculado no servidor: início + duração do serviço. O preço é copiado do serviço (`price`), para mudar o preço depois não reescrever o histórico;
- só em dia de expediente, começando e terminando dentro dele, com o início numa fatia contada a partir da abertura. Os valores ficam em `config/barberflow.php` (`BUSINESS_OPENING`, `BUSINESS_CLOSING`, `BUSINESS_SLOT_MINUTES` e `BUSINESS_DAYS`; padrão 09:00 às 19:00, de 30 em 30 minutos, de segunda a sábado);
- nada no passado. O "agora" é o `now()` do PHP, no fuso `America/Sao_Paulo`, e nunca o relógio do banco: assim o resultado é o mesmo no SQLite e no MySQL e os testes controlam o relógio com `travelTo`;
- nada depois de `BOOKING_DAYS_AHEAD` dias a partir de hoje (padrão 60), em qualquer perfil. Assim ninguém marca um horário no ano 9999, que não aparece na Agenda e trava com `409` a exclusão do barbeiro e do serviço;
- serviço ativo; cliente, barbeiro e serviço não excluídos. Para o cliente, serviço inativo ou excluído é como um id que não existe (a mesma mensagem do `exists`), igual ao `404` do `GET /api/services/{id}`; a barbearia vê o motivo;
- sem sobreposição `[início, fim)` na agenda do barbeiro (erro em `start_time`) nem na do cliente (erro em `client_id`). Cancelado e faltou não ocupam o horário, e um atendimento pode começar na hora em que o outro termina;
- o cliente que agenda pela conta dele tem no máximo `CLIENT_MAX_ACTIVE_APPOINTMENTS` agendamentos ativos de hoje em diante (padrão 3; erro em `client_id`). Assim uma conta só não lota a agenda de um barbeiro. A barbearia agenda para ele sem esse limite;
- remarcar só com o status agendado ou confirmado; as regras rodam de novo sem contar o próprio agendamento, e o preço só muda se o serviço mudar.

**Status** (`appointments.status`):

| Status | Nome | Pode ir para |
|---|---|---|
| `scheduled` | Agendado | `confirmed`, `in_progress`, `cancelled`, `no_show` |
| `confirmed` | Confirmado | `in_progress`, `cancelled`, `no_show` |
| `in_progress` | Em atendimento | `completed` |
| `completed` | Concluído | (final) |
| `cancelled` | Cancelado | (final) |
| `no_show` | Faltou | (final) |

Em atendimento, concluído e faltou só valem a partir do horário de início. O cliente só cancela, e só até `CLIENT_CANCEL_MIN_HOURS` horas antes; a barbearia cancela a qualquer momento. Não existe exclusão de agendamento: cancelar é mudar o status, e a linha fica no histórico.

**Concorrência.** Dois pedidos podem tentar o mesmo horário ao mesmo tempo (dois atendentes, ou o cliente pelo celular e o barbeiro no balcão). Agendar e remarcar rodam numa transação (`DB::transaction`, com até 3 tentativas se o MySQL desfizer uma por deadlock) que trava as linhas sempre na mesma ordem:

1. o barbeiro (`lockForUpdate`);
2. o cliente (`lockForUpdate`);
3. o serviço (`sharedLock`: o agendamento não muda o serviço, mas a exclusão dele, que trava a linha para escrita, espera);
4. na remarcação, por último, o próprio agendamento.

A ordem fixa evita que dois pedidos esperem um pelo outro em círculo (deadlock). Com o barbeiro travado, dois pedidos para a mesma agenda passam um de cada vez; com o cliente travado, dois pedidos do mesmo cliente também. Todo par de pedidos que pode conflitar divide a trava de um barbeiro ou de um cliente.

Depois das travas vêm as consultas sem trava: o limite do cliente e a sobreposição. No MySQL (InnoDB, nível `REPEATABLE READ`), uma leitura sem trava enxerga a "foto" do banco tirada na primeira leitura sem trava da transação. As leituras com trava não tiram a foto, então ela nasce na consulta do limite ou da sobreposição, depois de o pedido anterior terminar e soltar a trava: o segundo pedido enxerga o agendamento do primeiro. Por isso nenhuma leitura sem trava pode entrar antes das travas.

A sobreposição fica sem `lockForUpdate` de propósito. Com ele, o MySQL trava também o intervalo do índice (`barber_id`, `date`, `start_time`) que a consulta percorreu, e dois agendamentos de barbeiros e clientes diferentes no mesmo dia esperam um pelo outro para gravar: com 10 pedidos assim ao mesmo tempo, 4 ou 5 caíam por deadlock depois das 3 tentativas e saíam como erro 500. Sem a trava na consulta, os 10 passam, e com o mesmo barbeiro ou o mesmo cliente só 1 passa e os outros 9 recebem `422`.

A exclusão de cliente, barbeiro ou serviço (`App\Models\Concerns\HasAppointments`) trava a linha do cadastro antes de procurar agendamentos de hoje em diante ainda ativos. Com eles, a resposta é `409` ("Este cadastro tem agendamentos futuros. Cancele ou remarque antes de excluir."). Um agendamento feito ao mesmo tempo que a exclusão ou termina antes (e a exclusão o vê) ou espera a exclusão terminar (e é recusado, porque o cadastro foi excluído).

O SQLite dos testes não trava linha, então essas travas só são conferidas no MySQL: o `phpunit.mysql.xml` roda a suíte inteira num MySQL 8.4 descartável (`composer test:mysql`, veja o README), e só lá roda o `AppointmentConcurrencyTest` (no `composer test` ele aparece como pulado). Ele abre duas conexões, como dois pedidos ao mesmo tempo, e confere em passos de ordem fixa que agendamentos sem conflito não esperam um pelo outro, que o barbeiro, o cliente e o serviço ficam travados até o agendamento terminar e que o segundo pedido enxerga o que o primeiro gravou enquanto ele esperava. No SQLite local, as transações são `IMMEDIATE` (trava de escrita no começo) com `busy_timeout` de 5 segundos: uma gravação espera a outra em vez de falhar com "database is locked". A prova com pedidos simultâneos de verdade (vários processos) fica para o teste de carga `concorrencia.js` (k6), na trilha de nuvem.

**Telas.** A Agenda monta uma coluna por barbeiro (o barbeiro vê só a dele; no celular, uma lista por barbeiro) e posiciona cada bloco pelo `el.style` (a CSP barra só o `style=""` escrito no HTML). O Agendar busca os horários livres (`GET /api/barbers/{id}/available-slots`) e mostra cada um como um botão; se o horário marcado for tomado no meio do caminho, a API responde `422` em `start_time` e a tela avisa e busca os horários de novo. Na remarcação que mantém barbeiro, serviço, data e horário, a tela manda só as observações: o barbeiro anota algo mesmo depois do início, quando o horário atual já saiu da lista de livres. O expediente, o limite de dias e as regras de cada status chegam às telas por data-attributes (`config/barberflow.php` e `AppointmentStatus::catalog()`), e "hoje" e "agora" são calculados no fuso da barbearia (meta `app-timezone` do layout). A API continua sendo quem decide.

### Lembretes

Decisão do plano (12/11/2026): lembrete por e-mail 24 horas antes, síncrono no agendador, com `onOneServer()` e sem worker de fila. As regras ficam em `App\Services\ReminderSender`, o e-mail em `App\Mail\AppointmentReminder` (Markdown em pt-BR, `resources/views/mail/appointment-reminder.blade.php`), o comando em `App\Console\Commands\SendAppointmentReminders` e o agendamento em `routes/console.php`. A coluna `appointments.reminder_sent_at` (migration nova) guarda quando o lembrete saiu.

**Fluxo do comando** (`appointments:send-reminders`, a cada 15 minutos):

1. busca os agendamentos agendados ou confirmados com `reminder_sent_at` nulo, de hoje até o dia em que a janela termina. O banco filtra só pela data (`appointments.date`, já em `Y-m-d`), de 100 em 100 (`lazyById`);
2. em PHP, fica só com os que começam depois de agora e até `REMINDER_HOURS_BEFORE` horas à frente, com o limite incluído. O "agora" é o `now()` do PHP no fuso do `APP_TIMEZONE`, sem função de data do banco, então a conta é igual no SQLite e no MySQL;
3. pula o cliente excluído e o e-mail do domínio `barberflow.invalid`, o de quem foi excluído (conferido sem diferenciar maiúsculas, por `ReleasesEmailOnDelete::hasReleasedEmail()`);
4. **reserva a linha** antes de enviar: `UPDATE appointments SET reminder_sent_at = agora WHERE id = ? AND reminder_sent_at IS NULL`, conferindo também o status, a data, o horário, o barbeiro e o serviço que leu. Só envia quem pegou a reserva (uma linha afetada); quem não pegou conta como pulado. Assim duas rodadas ao mesmo tempo, ou a rodada e o envio manual, não mandam o mesmo lembrete duas vezes, e o e-mail fala do horário que estava na linha na hora da reserva;
5. manda o e-mail na hora, sem fila;
6. **falha:** se o envio dá erro, a reserva é desfeita (o `reminder_sent_at` volta a nulo, mas só se a linha ainda tiver a própria reserva, com o `reminder_sent_at`, a data e o horário reservados: uma remarcação no meio do caminho já a zerou, e o lembrete do horário novo fica, mesmo gravado no mesmo segundo), e o log recebe "Lembrete por e-mail não enviado." só com o `appointment_id`. **Nova tentativa:** a rodada seguinte, 15 minutos depois, pega o agendamento de novo, enquanto ele ainda não começou. Com alguma falha, o comando termina com o código 1, para o monitoramento perceber.

A reserva e o retorno não mexem no `updated_at`. Remarcar a data ou o horário volta o `reminder_sent_at` a nulo (`AppointmentScheduler::reschedule`), e o lembrete do horário novo sai na rodada dele; trocar só o barbeiro, o serviço ou as observações não muda nada.

**"Enviar lembrete agora"** (`POST /api/appointments/{id}/reminder`, `AppointmentPolicy::sendReminder`): numa transação, trava só a linha do agendamento (`lockForUpdate`, a penúltima da ordem global barbeiro, cliente, serviço, agendamento, pagamento), lê o resto depois da trava e confere as regras: status agendado ou confirmado, antes do início, destinatário que recebe e-mail e um envio a cada `resend_minutes` (10 minutos, fixo na config) por agendamento, contando o lembrete do comando e mesmo depois de uma remarcação: o envio manual também fica registrado no cache (`RateLimiter`, chave `appointment-reminder:{id}`), e a remarcação, que zera o `reminder_sent_at`, não apaga esse registro. Passou, grava o `reminder_sent_at` e solta a trava; o e-mail sai depois, para a trava não ficar presa enquanto o servidor de e-mail responde. Se o envio falha, o registro do cache é apagado, o `reminder_sent_at` volta ao valor anterior (nulo ou o do envio antigo, com a mesma conferência da própria reserva do comando), e a resposta é `503`. O envio manual conta como o lembrete do agendamento: depois dele, o comando não manda outro. A rota ainda tem o limitador `reminders`: 10 envios por minuto por usuário.

**Agendador:** `everyFifteenMinutes()->onOneServer()->withoutOverlapping(10)`. O `onOneServer()` só vale entre instâncias com cache compartilhado (o Redis da nuvem). O `withoutOverlapping(10)` faz a trava vencer em 10 minutos, e não nas 24 horas do padrão do Laravel: um servidor que cair no meio de uma rodada não segura os lembretes por um dia. Mesmo que duas rodadas se encontrem, a reserva impede o lembrete repetido. Quem chama o agendador é o `php artisan schedule:work` no local e o cron com `schedule:run` a cada minuto no servidor.

Decisões:

- **E-mail síncrono, sem fila:** o volume de uma barbearia é pequeno e não há worker para manter. O preço é que um servidor de e-mail lento atrasa a rodada.
- **Limites do envio manual:** um a cada 10 minutos por agendamento, contando o do comando e mesmo depois de remarcar (sem o registro no cache, remarcar e enviar de novo mandaria um e-mail a cada troca de horário), e 10 por minuto por usuário (`throttle:reminders`), para uma conta não disparar lembretes em série, do remetente da barbearia, para os endereços de clientes que ela mesma cadastrou. Os dois respondem `429` com `Retry-After`. O renderer de `429` do `bootstrap/app.php` mantém a mensagem de quem lançou o erro ("Um lembrete deste agendamento saiu há menos de 10 minutos..."); o do middleware `throttle` continua com a mensagem genérica.
- **LGPD:** o e-mail não leva nome, e-mail, telefone nem observações, e o assunto é fixo ("Lembrete do seu horário na {barbearia}"). O cabeçalho `X-Appointment-Id` leva só o id, para achar a mensagem sem abrir o corpo, e o que o `ReminderSender` grava no log da aplicação leva só o id, nunca a mensagem do erro (ela pode trazer o endereço). O `MAIL_MAILER=log` do `.env.example` só serve no local: ele grava a mensagem inteira, com o endereço do cliente e os dados do horário, no `laravel.log`. Fora do local, o envio de verdade (`smtp`) não deixa dado pessoal no log.
- **Link do e-mail pelo `APP_URL`:** o botão de Meus horários (só para quem tem conta) não depende do `Host` da requisição, porque o comando roda sem requisição.
- **Dados da barbearia na config:** `BARBERSHOP_NAME` (vazio, vale o `APP_NAME`, porque o assunto precisa de um nome), `BARBERSHOP_ADDRESS` e `BARBERSHOP_PHONE` (vazios, ficam fora do e-mail).

**Tela:** o detalhe da Agenda mostra quando o lembrete saiu ("Enviado em 14/11/2026 às 21:03") ou, enquanto ele ainda pode sair, que não saiu e em quantas horas antes o e-mail automático sai (`data-reminder-hours`, da config). O botão "Enviar lembrete agora" aparece no agendado ou confirmado que ainda não começou (`catalog().remindable`), para o admin e para o barbeiro do agendamento. O sucesso, o `422` e o `429` aparecem num aviso dentro do próprio detalhe, com a mensagem da API.

### Pagamentos

O pagamento é do atendimento concluído: zero ou um por agendamento. As regras ficam em `App\Services\PaymentRecorder`, os status e as transições só em `App\Enums\PaymentStatus` e as formas em `App\Enums\PaymentMethod`.

**Regras** (as recusas são `422`, no campo do formulário):

- só atendimento com o status concluído recebe pagamento (erro em `appointment_id`);
- um pagamento por atendimento, para sempre: pendente, pago ou estornado, ele é o único. Um segundo registro, mesmo depois do estorno, é recusado. Assim o histórico de um atendimento nunca tem dois valores somando no faturamento;
- o valor padrão é o `price` copiado no agendamento, o preço do dia em que ele foi marcado. O admin e o barbeiro podem registrar outro valor (desconto, cortesia com valor 0);
- o status padrão é pago, e o `paid_at` vem do `now()` do servidor. Pendente fica com o `paid_at` nulo até virar pago; o estorno mantém a data em que foi pago;
- formas: dinheiro, Pix, cartão de crédito e cartão de débito;
- com `use_loyalty_reward`, o pagamento usa um prêmio da fidelidade: o desconto vem da config e o valor é calculado (veja [Fidelidade](#fidelidade)).

**Status** (`payments.status`):

| Status | Nome | Pode ir para | Quem |
|---|---|---|---|
| `pending` | Pendente | `paid` | admin, ou o barbeiro do agendamento |
| `paid` | Pago | `refunded` | só o admin (estorno) |
| `refunded` | Estornado | (final) | |

A correção de valor, forma e observações é só do admin e vale em qualquer status, sem mudar o status, o `paid_at` nem o agendamento. Não existe exclusão de pagamento: estornar é mudar o status, e a linha fica no histórico.

**Concorrência.** Registrar roda numa transação que trava a linha do cliente e depois a do agendamento (`lockForUpdate`, na ordem global barbeiro, cliente, serviço, agendamento, pagamento) antes de conferir o status, se já existe pagamento e os prêmios da fidelidade, com a mesma regra da agenda: nenhuma leitura sem trava antes das travas. Dois registros para o mesmo atendimento passam um de cada vez, e o segundo enxerga o pagamento do primeiro e é recusado sem tentar gravar. A busca pelo pagamento que já existe fica sem trava de propósito: no MySQL, uma busca com trava por um `appointment_id` que ainda não existe trava o intervalo do índice, e registros de atendimentos diferentes esperariam um pelo outro. O índice `unique` de `appointment_id` é a última barreira: se mesmo assim um segundo pagamento chegar ao `insert`, a violação vira o mesmo `422`, nunca um erro 500. Se o barbeiro do agendamento mudou entre a conferência da permissão e a trava, a resposta é `409`. Mudar o status também trava a linha do pagamento, para dois pedidos ao mesmo tempo não pularem as transições. O `PaymentConcurrencyTest` confere a trava no MySQL (`composer test:mysql`).

**LGPD.** Excluir o cliente apaga também as observações dos pagamentos dos agendamentos dele (`Client::anonymizeAndDelete()`), logo depois das dos agendamentos: as duas são texto livre. O pagamento continua no histórico, e registrar o pagamento de um atendimento de cliente excluído continua permitido, mas sem observações: registrar ou corrigir com `notes` dá `422` (`Client::NOTES_AFTER_DELETION`), porque nada apagaria de novo um texto escrito depois da exclusão. A conferência vem depois da trava (a do agendamento no registro e a do pagamento na correção), e a exclusão trava essas linhas antes de apagar as observações: o pedido feito ao mesmo tempo ou termina antes (e tem as observações apagadas) ou espera a exclusão e é recusado.

**Telas.** A tela de Pagamentos (admin e barbeiro) lista os atendimentos concluídos sem pagamento (`GET /api/appointments?without_payment=1`), cada um com o "Registrar pagamento", e a tabela dos pagamentos com filtros (período, forma, status e, para o admin, barbeiro), os totais do filtro (`totals`) e paginação; no celular, a tabela vira uma lista. O "Registrar pagamento" abre um modal com o valor já preenchido pelo preço, a forma e as observações, e o mesmo modal aparece no detalhe da Agenda para o atendimento concluído sem pagamento (com pagamento, o detalhe mostra o status dele). Corrigir, marcar como pago e estornar ficam na linha de cada pagamento, conforme o perfil e as transições do `PaymentStatus::catalog()`, que chega à tela por data-attribute; marcar como pago e estornar pedem confirmação num modal da própria tela, nunca no `confirm()` do navegador. Em Meus horários, o cliente vê se cada atendimento concluído está pago, pelo pagamento resumido que vem em cada agendamento (`payment`, só com `id` e `status`).

### Fidelidade

Decisão do plano (12/11/2026): a cada 10 atendimentos concluídos, o seguinte sai com 100% de desconto (um corte grátis), com os dois números em `config/barberflow.php` (`LOYALTY_VISITS` e `LOYALTY_DISCOUNT_PERCENT`, lidos do `.env`). As regras ficam em `App\Services\LoyaltyService`, e quem aplica o prêmio é o `PaymentRecorder`.

**Regras:**

- visita é um atendimento concluído do cliente. O atendimento pago com o prêmio não é visita e, na hora de pagar, o próprio atendimento que está sendo pago também fica de fora: ele ainda não ajuda a ganhar o prêmio que o pagaria;
- prêmios ganhos: visitas ÷ `LOYALTY_VISITS`, sem as casas decimais. Usados: os pagamentos com o prêmio que não foram estornados (o pendente conta como usado). Disponíveis: ganhos menos usados, nunca negativo, mesmo se o `LOYALTY_VISITS` subir depois de prêmios já usados;
- progresso: visitas desde o último prêmio ganho, de 0 a `LOYALTY_VISITS` menos 1 (ex.: 7 de 10);
- o desconto é o `price` do agendamento × `LOYALTY_DISCOUNT_PERCENT` ÷ 100, arredondado no centavo, e o valor é o que sobra. A conta é feita em centavos (50% de 35,55 dá desconto 17,78 e valor 17,77);
- sem prêmio disponível, o registro com `use_loyalty_reward` dá `422`. O `amount` mandado junto com o prêmio, mesmo 0, também dá `422`: o valor é calculado.

**Modelo:** `payments.discount` (decimal 8,2, padrão 0) e `payments.loyalty_reward` (booleano, padrão falso), numa migration nova. Os dois só são gravados no registro; a correção do admin muda o valor, a forma e as observações, mas não o desconto nem o prêmio. Não existe tabela de prêmios: eles são contados dos atendimentos e dos pagamentos, então nunca ficam fora de sincronia com o histórico, e o estorno devolve o prêmio sem nenhuma escrita a mais.

**Concorrência:** o registro trava o cliente e depois o agendamento, antes de contar os prêmios. Dois pagamentos do mesmo cliente com o prêmio, ao mesmo tempo, passam um de cada vez, e o segundo já conta o prêmio que o primeiro usou. O `LoyaltyConcurrencyTest` confere essa trava no MySQL.

**Listagem sem uma consulta por cliente:** o `LoyaltyService::withCounts()` acrescenta à consulta da página de clientes duas subconsultas (`withCount` das visitas e dos prêmios usados), e o `attachSummary()` monta o resumo de cada cliente e esconde as duas colunas da conta. O `LoyaltyApiTest` confere que o número de consultas não cresce com o número de clientes.

Decisões:

- **Estorno:** o do pagamento com o prêmio devolve o prêmio, e o atendimento continua fora das visitas, para o cliente não ganhar a visita e o prêmio de volta ao mesmo tempo. O de um pagamento comum mantém a visita, porque o atendimento aconteceu.
- **A conta do pagamento:** o `GET /api/clients/{id}/loyalty?appointment_id=` deixa de fora o atendimento que está sendo pago, a mesma conta do `POST /api/payments`. Sem isso, o atendimento que completa as 10 visitas apareceria com prêmio no modal de pagamento, e a API recusaria. O agendamento é buscado só entre os deste cliente que o usuário pode ver (`Appointment::scopeVisibleTo`): o de outro cliente, o que não existe e o de uma agenda que o usuário não vê dão o mesmo `422`. Com um `403` só para o último, o barbeiro descobriria, testando os ids, quais agendamentos de outras agendas são do cliente.
- **O prêmio vale para qualquer serviço** e não vence: o desconto é sobre o preço do atendimento pago.

**Telas:**

- **Clientes:** a coluna Fidelidade ("7/10" e o selo "Prêmio disponível"), com o resumo que vem em cada cliente da listagem. No celular, a tabela continua cabendo nos 390px: o telefone também quebra linha, e as células têm um pouco menos de espaço entre si.
- **Modal de pagamento** (Pagamentos e o detalhe da Agenda): ao abrir o registro, consulta a fidelidade com o `appointment_id`. Com prêmio disponível, mostra o aviso e a caixa "Usar o prêmio de fidelidade"; marcada, o valor fica travado (somente leitura, com fundo cinza) no que sobra do desconto, a dica mostra o desconto e o pagamento vai sem `amount` e com `use_loyalty_reward`. Se a consulta falha, o modal avisa e o pagamento segue sem o prêmio. A lista de Pagamentos mostra o desconto no pagamento que usou o prêmio.
- **Meus horários:** o cliente vê o próprio progresso numa barra ("3 de 10 visitas" e "Faltam 7 visitas para você ganhar um atendimento grátis.") e o prêmio disponível, com o aviso de que a barbearia aplica o desconto na hora do pagamento.

### Dashboard

`GET /api/dashboard?from=&to=`, só para o admin (Gate `view-dashboard`), com as definições em `App\Services\DashboardMetrics`. Sem período, vale o mês atual no fuso da aplicação; o período tem no máximo 366 dias.

| Número | Definição |
|---|---|
| Faturamento | soma dos pagamentos pagos dos atendimentos do período; pendentes e estornados ficam de fora. Também por forma (sempre as 4) e por dia (todos os dias do período) |
| Atendimentos | agendamentos do período, no total e por status (sempre os 6) |
| Ticket médio | faturamento ÷ quantidade de pagamentos pagos com valor. O de valor zero (o corte grátis da fidelidade ou uma cortesia) não entra na quantidade nem nas formas de pagamento, porque não entrou dinheiro; o atendimento dele continua contando como concluído |
| Taxa de faltas | faltou ÷ (concluído + faltou), em %. Cancelado não conta como falta |
| Ocupação | minutos ocupados ÷ minutos disponíveis, em %. Ocupados: a duração dos agendamentos agendados, confirmados, em atendimento e concluídos. Disponíveis: minutos de expediente por dia × dias de expediente do período × barbeiros (os cadastrados até o último dia do período e não excluídos antes do primeiro) |
| Top 5 serviços | os serviços com mais atendimentos concluídos, com o faturamento deles |
| Por barbeiro | concluídos, faltas e faturamento de cada barbeiro |

Decisões:

- **Tudo pela data do atendimento.** O faturamento de um dia é o dos atendimentos daquele dia, mesmo com o pagamento registrado depois: assim o dashboard bate com a agenda.
- **Consultas agregadas e iguais nos dois bancos.** Cada número é um `count` ou `sum` com `group by`, e o número de consultas não muda com o volume. Nenhuma consulta usa função de data, que muda do SQLite para o MySQL: o `appointments.date` já é o texto `Y-m-d`, e a duração (término menos início) é calculada em PHP, num grupo por par de horários. A migration `add_date_index_to_appointments_table` acrescenta o índice (`date`, `start_time`) que os filtros por período usam.
- **Formato:** dinheiro em texto com 2 casas, como o `price`; taxas em % com 1 casa e `null` quando o divisor é zero (nenhum concluído nem falta, nenhum barbeiro ou dia de expediente), em vez de um 0% que não diz nada.
- **Tela:** é a tela inicial do admin. Tem o seletor de período (mês atual, mês passado e personalizado, que fica no endereço da página), os cartões de faturamento, atendimentos, ticket médio, taxa de faltas e ocupação, as barras de faturamento por dia e por forma e as tabelas de top serviços e por barbeiro. As barras são CSS puro, sem biblioteca: a largura de cada uma vai por `el.style` no `pages.js`, que a CSP permite, e cada linha tem o valor escrito ao lado. Com mais de 62 dias, o faturamento por dia vira por mês, para a lista não ficar com centenas de linhas.

### Segurança das requisições

- **Cabeçalhos** (`App\Http\Middleware\SecurityHeaders`, o primeiro middleware, para valer também nas respostas de erro): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin` (o Sanctum precisa do `Referer` das telas), `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Content-Security-Policy` (ligada em produção ou por `SECURITY_CSP`) e `Strict-Transport-Security` em HTTPS. O `X-Powered-By` sai da resposta.
- **CSP sem nada inline:** `script-src 'self'` e `style-src 'self'`. As telas não têm `style=""` nem `<script>` no HTML: o CSS próprio fica em `resources/css/pages.css` e o JS em `resources/js/pages.js`, entregues pelo Vite.
- **Limites de requisição** (`AppServiceProvider`, valores em `config/barberflow.php`): toda a API, 120 por minuto por usuário ou IP; login, 5 senhas erradas por e-mail + IP e 20 tentativas por IP; cadastro, 5 por IP; "esqueci a senha", 5 por e-mail + IP; "Enviar lembrete agora", 10 por minuto por usuário (além do um a cada 10 minutos por agendamento, no `ReminderSender`). Passou do limite, `429` com `Retry-After`. O bloqueio das senhas erradas (`App\Exceptions\TooManyLoginAttemptsException`) é um erro de validação no campo e-mail, e o `bootstrap/app.php` acrescenta a ele os mesmos cabeçalhos dos outros `429`. O `/api/health` fica fora.
- **CORS** restrito ao `APP_URL` (ou a `CORS_ALLOWED_ORIGINS`) e sem cookies: de outro site, só com token.
- **Proxies e hosts:** `TRUSTED_PROXIES` diz em qual balanceador confiar para ler o IP real e o HTTPS; o `trustHosts` aceita só o host do `APP_URL`, os IPs privados (health check do ALB) e os nomes de `TRUSTED_HOSTS`. Os links que o app monta não dependem do `Host`: fora do local, saem sempre do `APP_URL` (veja "Links dos e-mails", acima). Com o `APP_URL` em `https`, o `TRUSTED_PROXIES` também é o que faz o link de confirmação de e-mail valer, porque a assinatura cobre o endereço `https`.
- **Configuração:** tudo que vem do ambiente passa por `config/barberflow.php`, nunca por `env()` fora de `config/`, porque o `php artisan optimize` congela a configuração.
- **Erros em pt-BR:** a API responde 401 "Não autenticado.", 403 "Você não tem permissão para esta ação.", 419 "Sua sessão expirou. Recarregue a página." e 429 "Muitas requisições. Tente de novo em N segundos." (`bootstrap/app.php`). As telas têm páginas de erro próprias (403, 404, 419, 429, 500 e 503) em `resources/views/errors`.

## 4. Estrutura de pastas

```
app/
  Console/Commands/
    SendAppointmentReminders.php  # appointments:send-reminders (o lembrete do agendador)
  Enums/
    UserRole.php           # perfis: admin, barber e client
    AppointmentStatus.php  # status da agenda, nomes em português, transições e quem recebe lembrete
    PaymentStatus.php      # status do pagamento (pendente, pago, estornado) e transições
    PaymentMethod.php      # formas de pagamento (dinheiro, Pix, crédito e débito)
  Exceptions/
    TooManyLoginAttemptsException.php  # 429 do login bloqueado, com Retry-After na API
  Http/Controllers/
    ServiceController.php
    ClientController.php         # clientes, com a fidelidade na listagem e em GET /api/clients/{id}/loyalty
    BarberController.php
    AppointmentController.php    # agenda: listar, agendar, remarcar, mudar o status e enviar o lembrete agora
    AvailableSlotController.php  # GET /api/barbers/{id}/available-slots
    PaymentController.php        # pagamentos: listar com totais, registrar, corrigir e mudar o status
    DashboardController.php      # GET /api/dashboard (só o admin)
    HealthController.php   # GET /api/health
    AuthController.php     # login, logout, usuário, senha, cadastro e "esqueci a senha" da API
    HomeController.php     # GET /: tela inicial de cada perfil (admin no dashboard)
    Auth/                  # o mesmo pelas telas: SessionController, RegisteredUserController,
                           # EmailVerificationController e PasswordResetController
  Http/Middleware/
    SecurityHeaders.php    # cabeçalhos de segurança em toda resposta
    EnsureEmailIsVerified.php  # middleware verified, com a resposta da API em pt-BR
  Listeners/
    LinkClientToVerifiedUser.php  # liga a conta ao cadastro de cliente na confirmação do e-mail
  Mail/
    AppointmentReminder.php  # o e-mail do lembrete (Markdown em pt-BR, com o cabeçalho X-Appointment-Id)
  Models/
    Service.php
    Client.php
    Barber.php
    Appointment.php
    Payment.php
    User.php
    Concerns/
      ReleasesEmailOnDelete.php  # e-mail removido+{id}@barberflow.invalid de quem é excluído
      HasAppointments.php        # agendamentos do cadastro e o 409 ao excluir com agendamento futuro
  Policies/                # ServicePolicy, BarberPolicy, ClientPolicy, AppointmentPolicy e PaymentPolicy
  Rules/NotOnlySpaces.php  # recusa texto só de espaços (senha do login do barbeiro)
  Services/
    AppointmentScheduler.php  # regras da agenda, horários livres e travas
    ReminderSender.php        # lembrete por e-mail: o do comando (com a reserva da linha) e o "enviar agora"
    PaymentRecorder.php       # regras do pagamento: registrar (com trava e o prêmio) e mudar o status
    LoyaltyService.php        # fidelidade: visitas, progresso, prêmios e o desconto
    DashboardMetrics.php      # números do dashboard, cada um definido num docblock
    Auth/                  # LoginAttempt, ClientRegistration e PasswordResetter
bootstrap/app.php          # roteamento, middlewares e erros em JSON (401, 403, 404, 405, 409, 419 e 429 em português)
config/barberflow.php      # limites, segurança, admin inicial, expediente, janela de cancelamento, fidelidade,
                           # lembrete e dados da barbearia, lidos do .env
database/
  migrations/              # definição das tabelas
  seeders/                 # DatabaseSeeder, AdminUserSeeder e ServiceSeeder
lang/
  pt_BR/                   # mensagens de validação, auth, senha e paginação
  pt_BR.json               # frases soltas do framework (inclusive os e-mails)
resources/
  views/                   # telas: layouts (app e guest), auth, account, appointments, payments, dashboard, errors e cadastros
    mail/                  # o e-mail do lembrete
  js/pages.js              # comportamento das telas e chamadas à API
  css/pages.css            # estilos próprios das telas
routes/
  api.php                  # rotas da API
  web.php                  # rotas das telas
  console.php              # agendador: o lembrete a cada 15 minutos e a limpeza dos tokens vencidos
tests/Feature/             # testes dos endpoints, da agenda, dos lembretes, dos pagamentos, da fidelidade, do
                           # dashboard, da autenticação e das telas
phpunit.xml                # testes no SQLite em memória (composer test)
phpunit.mysql.xml          # a mesma suíte num MySQL descartável (composer test:mysql)
docs/                      # documentação
```

## 5. Modelo de dados atual

Tabelas de domínio implementadas:

```mermaid
erDiagram
    USERS ||--o| BARBERS : "login do barbeiro"
    USERS ||--o| CLIENTS : "conta do cliente"
    CLIENTS ||--o{ APPOINTMENTS : agenda
    BARBERS ||--o{ APPOINTMENTS : atende
    SERVICES ||--o{ APPOINTMENTS : contém
    APPOINTMENTS ||--o| PAYMENTS : "pagamento"
    PAYMENTS {
        bigint id PK
        bigint appointment_id FK "unique, restrict"
        decimal amount "8,2"
        decimal discount "8,2, padrão 0, desconto da fidelidade"
        boolean loyalty_reward "padrão false, usou o prêmio"
        string method "cash | pix | credit_card | debit_card"
        string status "pending | paid | refunded"
        timestamp paid_at "nullable, do servidor"
        text notes "nullable"
        timestamp created_at
        timestamp updated_at
    }
    APPOINTMENTS {
        bigint id PK
        bigint client_id FK "restrict"
        bigint barber_id FK "restrict"
        bigint service_id FK "restrict"
        date date "Y-m-d"
        time start_time "H:i:s"
        time end_time "H:i:s, calculado"
        string status "padrão scheduled"
        decimal price "8,2, cópia do serviço"
        text notes "nullable"
        timestamp reminder_sent_at "nullable, lembrete já enviado"
        timestamp created_at
        timestamp updated_at
    }
    SERVICES {
        bigint id PK
        string name
        int duration "minutos"
        decimal price "8,2"
        string status "active | inactive"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable, exclusão lógica"
    }
    CLIENTS {
        bigint id PK
        bigint user_id FK "nullable, unique"
        string name
        string email UK
        string phone "nullable, 20"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable, exclusão lógica"
    }
    BARBERS {
        bigint id PK
        bigint user_id FK "nullable, unique"
        string name
        string email UK
        string phone "nullable, 20"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable, exclusão lógica"
    }
    USERS {
        bigint id PK
        string name
        string email UK
        string password
        string role "admin | barber | client, sem padrão"
        string phone "nullable, 20"
        timestamp email_verified_at
        string remember_token
        timestamp created_at
        timestamp updated_at
    }
```

Tabelas de infraestrutura do Laravel: `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` e `personal_access_tokens` (Sanctum).

`barbers.user_id` e `clients.user_id` ligam o cadastro ao login (`nullOnDelete`: apagar o usuário só desliga o cadastro). `users.phone` guarda o telefone do cadastro de conta até o cliente confirmar o e-mail, quando ele passa para o cadastro de cliente.

`appointments` liga cliente, barbeiro e serviço. As chaves estrangeiras são `restrict`: um cadastro com agendamento nunca é apagado de vez, só excluído de forma lógica, e o histórico continua apontando para ele (as relações do model usam `withTrashed()`). Os índices (`barber_id`, `date`, `start_time`) e (`client_id`, `date`) atendem às consultas de sobreposição e de horários livres, e o (`date`, `start_time`) às consultas por período de todos os barbeiros (dashboard, filtros e ordem das listagens). A tabela não tem `deleted_at`: cancelar é um status. O model grava sempre `date` como `Y-m-d` e os horários como `H:i:s`, igual no SQLite e no MySQL. `reminder_sent_at` é quando saiu o lembrete por e-mail: nulo enquanto não saiu, gravado só pelo `ReminderSender` (fora do `$fillable`) e de volta a nulo quando a data ou o horário são remarcados (veja [Lembretes](#lembretes)).

`payments` guarda o pagamento de um atendimento concluído, zero ou um por agendamento: o `appointment_id` é `unique` (a última barreira contra dois pagamentos para o mesmo atendimento) e a chave estrangeira é `restrict`, como as da agenda. `amount` segue o `price` (`decimal(8,2)`, texto com 2 casas no JSON). `status` não tem padrão no banco: quem grava sempre diz o status, junto com o `paid_at`. `discount` e `loyalty_reward` registram o prêmio da fidelidade (`"0.00"` e `false` sem ele); com o prêmio, no registro, `amount` é o que sobra do `price` depois do desconto (veja [Fidelidade](#fidelidade)). O índice (`status`, `method`) serve aos filtros da listagem e ao dashboard, que soma só os pagos, por forma. A tabela não tem `deleted_at`: estornar é um status.

### Histórico e LGPD (exclusão lógica)

`services`, `clients` e `barbers` usam *soft delete* (migration `add_soft_deletes_to_domain_tables` e o trait `SoftDeletes` nos models). O `DELETE` só preenche `deleted_at`: a linha continua no banco, para os agendamentos e pagamentos antigos continuarem apontando para ela, e some das listagens e das consultas por ID.

| Tabela | O que acontece no `DELETE` | Por quê |
|---|---|---|
| `clients` | nome vira "Cliente removido", e-mail vira `removido+{id}@barberflow.invalid`, telefone fica nulo e as observações (`notes`) dos agendamentos dele e dos pagamentos desses agendamentos são apagadas (`Client::anonymizeAndDelete()`). Depois disso, observação nova nesses agendamentos e pagamentos dá `422` (`Client::NOTES_AFTER_DELETION`) | LGPD: o histórico fica, mas sem dado que identifique a pessoa. As observações são texto livre e podem ter telefone, endereço ou dado de saúde, e nada apagaria de novo um texto escrito depois da exclusão |
| `barbers` | e-mail vira `removido+{id}@barberflow.invalid` e telefone fica nulo; o nome fica (`Barber::releaseEmailAndDelete()`) | o histórico precisa mostrar quem atendeu; o telefone não tem mais uso depois da exclusão (LGPD) |
| `services` | nada muda além do `deleted_at` | não há dado pessoal |

A troca do e-mail acontece na mesma transação da exclusão e libera o e-mail original no índice `unique` do banco: a pessoa pode ser cadastrada de novo. A regra `unique:tabela,email,{id}` da validação continua a mesma. O domínio `.invalid` é reservado (RFC 2606), então nenhum lembrete chega a um cadastro excluído por engano. A validação recusa e-mails `@barberflow.invalid` no cadastro e na edição: sem isso, alguém poderia cadastrar antes o endereço `removido+{id}` de outra pessoa e travar a exclusão dela num erro 500.

A validação sozinha não fecha essa porta no MySQL. A collation `utf8mb4_unicode_ci` compara sem diferenciar acento, então `removido+1@barberflow.ínvalid` passa no `not_regex` e, para o índice `unique`, é igual a `removido+1@barberflow.invalid` (o mesmo vale para a ligadura `ﬂ` e as letras largas, como `ｉ`). Por isso o endereço sai do trait `App\Models\Concerns\ReleasesEmailOnDelete`: antes de gravar, ele pergunta ao banco se o endereço já está em uso, e o banco compara do mesmo jeito que o índice. Se estiver, a exclusão usa `removido+{id}.{sufixo aleatório}@barberflow.invalid` e termina normalmente. O teste simula a colisão gravando o endereço reservado direto no banco, porque o SQLite dos testes compara letra por letra.

### Colunas da etapa de lembretes e fidelidade

Os lembretes e a fidelidade não criaram tabelas: as que já existiam ganharam colunas, em migrations novas (`add_reminder_sent_at_to_appointments_table` e `add_loyalty_to_payments_table`), já no diagrama acima. O `reminder_sent_at` evita que o lembrete por e-mail saia duas vezes, e o `discount` e o `loyalty_reward` registram o atendimento pago com o prêmio de quem completou o programa de fidelidade. Os prêmios não têm tabela própria: são contados dos atendimentos e dos pagamentos.

### Diferenças em relação ao esboço inicial

O esboço de arquitetura previa campos mais completos. Para o MVP, a equipe implementou o mínimo necessário para validar a API:

| Tabela | Previsto no esboço | Implementado | Observação |
|---|---|---|---|
| `barbers` | `user_id`, `phone`, `commission_rate`, `status` | `user_id`, `name`, `email`, `phone` | O vínculo com `users` entrou com a autenticação; comissão e status ficam para o financeiro |
| `clients` | `name`, `phone`, `email`, `birth_date`, `notes` | `user_id`, `name`, `email`, `phone` | O `user_id` entrou com a autenticação e liga o cadastro à conta do cliente quando ele confirma o e-mail; `birth_date` e `notes` ficaram de fora, porque a fidelidade conta os atendimentos e não precisou deles |
| `services` | `name`, `duration`, `price`, `status` | igual | Implementado como previsto |
| `appointments` | `client_id`, `barber_id`, `service_id`, `date`, `start_time`, `end_time`, `status` | os mesmos, mais `price`, `notes` e `reminder_sent_at` | `price` guarda o preço do dia do agendamento, para o histórico e os pagamentos; `notes` são as observações do atendimento; `reminder_sent_at` é quando saiu o lembrete por e-mail |
| `payments` | `appointment_id`, `amount`, `method`, `status`, `paid_at` | os mesmos, mais `notes`, `discount` e `loyalty_reward` | `appointment_id` é `unique`: um pagamento por atendimento. `notes` são as observações do pagamento (ex.: troco, desconto combinado); `discount` e `loyalty_reward`, o prêmio da fidelidade |

## 6. Plano de entrega

| Sprint | Escopo | Situação |
|---|---|---|
| 1 | Ambiente, migrations, CRUD de `services` | Entregue |
| 2 | CRUD de `clients` e `barbers`, testes de feature, paginação e busca, exclusão lógica com anonimização, mensagens em pt-BR, fuso de São Paulo, `/api/health`, autenticação (sessão e token), perfis e permissões, conta de cliente com confirmação de e-mail, limites de requisição e cabeçalhos de segurança | Entregue no código |
| 3 | Agenda e agendamento online (consulta de horários livres), sem sobreposição por barbeiro e por cliente, com as telas Agenda, Agendar e, para o cliente, Agendar e Meus horários | Entregue no código |
| 4 | Status do atendimento (entregue com a agenda), pagamentos (tela de Pagamentos, atalho no detalhe da Agenda e o "Pago" em Meus horários), dashboard básico (a tela inicial do admin), lembretes (comando no agendador e "Enviar lembrete agora" na Agenda), fidelidade (coluna em Clientes, prêmio no modal de pagamento e progresso em Meus horários) e documentação final | Pagamentos, dashboard, lembretes e fidelidade entregues no código; a documentação final fica para a entrega final |

Prioridades do produto (do esboço inicial): login e cadastros-base (alta) → agenda e agendamento online (alta) → status de atendimento, pagamentos e dashboard (média) → lembretes, fidelidade e relatórios avançados (baixa).

## 7. Limitações e riscos conhecidos

- **Usuário de teste com senha conhecida:** o `DatabaseSeeder` dá o perfil admin ao `test@example.com`, cuja senha está no código. Serve só para o ambiente local. Em produção, o admin vem do `AdminUserSeeder`, com a senha definida por quem instala, e o deploy nunca roda o seed de teste.
- **Cadastro de conta revela e-mail cadastrado:** o `POST /register` responde "Este e-mail já está cadastrado." (regra `unique`). O login e o "esqueci minha senha" não revelam. Esconder isso no cadastro pediria outro fluxo (avisar por e-mail em vez de responder na tela).
- **Limite por IP atrás do balanceador:** sem `TRUSTED_PROXIES`, todo visitante parece vir do IP do ALB e todos dividem a mesma contagem de requisições.
- **Página de até 100 itens:** as listagens não devolvem tudo de uma vez. Telas que precisam da lista inteira (como o Agendar) carregam os 100 primeiros em ordem de nome. A Agenda e Meus horários leem até 10 páginas de agendamentos.
- **Travas da agenda, do pagamento e da fidelidade só no MySQL:** o SQLite não trava linha. O `composer test:mysql` roda o `AppointmentConcurrencyTest`, o `PaymentConcurrencyTest` e o `LoyaltyConcurrencyTest`, que conferem as travas com duas conexões num processo só, em passos de ordem fixa; pedidos simultâneos de verdade (vários processos) ficam para o teste de carga `concorrencia.js`. A reserva do lembrete é um `UPDATE` condicional: o `ReminderCommandTest` e o `ReminderApiTest` mudam a linha logo antes dele (outra rodada, o envio manual, o cancelamento ou a remarcação) e logo antes do `UPDATE` que desfaz a reserva depois de uma falha (o `beforeQuery()` do `tests/TestCase.php`), sem um teste de duas conexões no MySQL.
- **Lembrete síncrono e dependente do agendador:** o comando manda um e-mail de cada vez, sem fila, e um servidor de e-mail lento atrasa a rodada. Sem o `schedule:work` (local) ou o cron com o `schedule:run` (servidor), o lembrete automático não sai; o "Enviar lembrete agora" funciona sem ele. O `onOneServer()` só vale entre instâncias com cache compartilhado.
- **Fidelidade simples:** o prêmio vale para qualquer serviço e não vence, e mudar o `LOYALTY_VISITS` muda a conta de todos os clientes, inclusive a dos prêmios já ganhos.
- **Horários livres por barbeiro:** a lista olha só a agenda do barbeiro escolhido. O conflito do cliente com outro barbeiro aparece só ao agendar (`422` em `client_id`).
- **Cancelamento pela barbearia depois do início:** a barbearia pode cancelar um agendamento que já começou. Na taxa de faltas do dashboard, um cancelamento em cima da hora não conta como falta.
- **Um pagamento por atendimento, para sempre:** depois do estorno, o atendimento não recebe outro pagamento. Cobrar de novo pede corrigir o pagamento estornado, que continua fora do faturamento.
- **Dashboard pela data do atendimento:** não há relatório pela data em que o dinheiro entrou. A ocupação não conhece a data de contratação: o barbeiro cadastrado no meio do período conta o período inteiro (o cadastrado depois do último dia não conta, para um período que já passou não mudar).
- **Busca simples:** `search` usa `LIKE '%texto%'`, que não aproveita índice. Serve para o volume de uma barbearia; com muitos milhares de cadastros, vale um índice de texto completo.
- **Validação nos controllers:** funciona bem no MVP; ao crescer, vale migrar para *Form Requests* e *API Resources* próprias para padronizar entrada e saída. Hoje só a paginação passa pelo `JsonResource::collection()`, que já entrega o formato `data`/`links`/`meta`; os itens saem como o model.
- **Sem desfazer exclusão:** a exclusão é lógica, mas não há rota para restaurar. No cliente, os dados pessoais já foram apagados e não voltam.
- **Datas antigas no banco local:** quem tinha dados gravados antes da troca do fuso (o padrão era UTC) vê esses horários deslocados em 3 horas. Não afeta dados novos.
- **Compatibilidade com PHP 8.5:** a versão é recente e alguns pacotes de terceiros podem ainda não suportá-la; conferir antes de adicionar dependências.

## 8. Evolução futura

Após o MVP: lembretes por mensagem (SMS ou WhatsApp) e confirmação de presença pelo lembrete, regras de fidelidade mais ricas (prêmio por serviço, validade do prêmio), relatórios, aplicativo mobile e, a longo prazo, transformação em SaaS multiempresa (várias barbearias na mesma plataforma), integração oficial com WhatsApp e pagamentos online.
