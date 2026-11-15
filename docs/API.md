# Referência da API do BarberFlow

API REST em JSON construída com Laravel. Todos os endpoints ficam sob o prefixo `/api`.

- **Base URL (local):** `http://localhost:8000/api`
- **Formato:** JSON (requisição e resposta)
- **Idioma:** mensagens em português do Brasil
- **Autenticação:** token Bearer do Laravel Sanctum (`POST /login`). As telas do próprio BarberFlow usam a sessão do navegador. Veja [Autenticação](#autenticação)
- **Permissões:** por perfil (admin, barbeiro e cliente). Veja [Perfis e permissões](#perfis-e-permissões)

## Convenções

Envie sempre os cabeçalhos:

```
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token>
```

O `Authorization` só fica de fora nas rotas abertas: `POST /login`, `POST /register`, `POST /forgot-password`, `POST /reset-password` e `GET /health`.

Qualquer rota que começa com `/api` responde em JSON, mesmo sem o `Accept`.

O JSON pode trazer os acentos escapados (`"n\u00e3o"` no lugar de `"não"`). É o mesmo texto: qualquer leitor de JSON, como o Postman ou o `JSON.parse`, mostra a palavra normal.

### Códigos de resposta

| Código | Significado | Quando ocorre |
|---|---|---|
| `200 OK` | Sucesso | Listagem, consulta, atualização, login e `/health` com tudo no ar |
| `201 Created` | Recurso criado | `POST` bem-sucedido |
| `202 Accepted` | Pedido aceito | Reenvio do e-mail de confirmação |
| `204 No Content` | Sem corpo | `DELETE`, logout e troca de senha bem-sucedidos |
| `401 Unauthorized` | Sem login | Sem token, ou com token vencido, revogado ou inválido |
| `403 Forbidden` | Sem permissão | O perfil não pode fazer essa ação, ou o cliente ainda não confirmou o e-mail |
| `404 Not Found` | Não encontrado | ID inexistente ou já excluído, rota que não existe, ou serviço inativo pedido pelo cliente |
| `405 Method Not Allowed` | Método errado | A rota existe, mas não com esse método (ex.: `DELETE /api/services` ou `DELETE /api/appointments/1`) |
| `409 Conflict` | Conflito com a agenda | Excluir cliente, barbeiro ou serviço com agendamento futuro ativo, ou remarcar (ou registrar o pagamento de) um agendamento que outra pessoa mudou no mesmo instante. A resposta diz o que fazer |
| `419` | Sessão expirada | Só nas telas: o token CSRF da sessão do navegador não vale mais |
| `422 Unprocessable Entity` | Falha de validação | Campo ausente, inválido, duplicado ou fora do limite, filtro de listagem inválido, e-mail ou senha errados no login, horário ocupado ou fora do expediente, mudança de status que não vale, pagamento de atendimento não concluído ou que já tem pagamento, prêmio de fidelidade sem direito ou com o valor junto, lembrete de agendamento que não recebe lembrete, período do dashboard maior que 366 dias |
| `429 Too Many Requests` | Limite de requisições | Passou do limite por minuto (veja [Limites de requisição](#limites-de-requisição)), ou pediu o "Enviar lembrete agora" do mesmo agendamento antes de 10 minutos, mesmo depois de remarcar |
| `503 Service Unavailable` | Fora do ar | `/health` quando o banco ou o cache não responde, ou o e-mail do "Enviar lembrete agora" que não saiu |

### Sem login (401)

Sem o cabeçalho `Authorization`, ou com um token vencido, revogado (logout) ou inválido:

```json
{ "message": "Não autenticado." }
```

### Sem permissão (403)

O perfil de quem chamou não pode fazer a ação (ex.: barbeiro excluindo cliente ou abrindo o dashboard, cliente listando clientes):

```json
{ "message": "Você não tem permissão para esta ação." }
```

Conta de cliente que ainda não confirmou o e-mail, em `/services`, `/clients`, `/barbers`, `/appointments` ou `/payments` (todas as rotas com login exigem a confirmação, menos as da própria conta: `/user`, `/user/password`, `/logout` e `/email/verification-notification`):

```json
{ "message": "Confirme seu e-mail para continuar." }
```

### Sessão expirada (419)

Só acontece nas chamadas feitas pelas telas, que usam a sessão do navegador: o token CSRF da sessão venceu (sessão expirada ou encerrada em outra aba). As telas levam a pessoa de volta ao login. Quem usa token Bearer nunca recebe `419`.

```json
{ "message": "Sua sessão expirou. Recarregue a página." }
```

### Muitas requisições (429)

```json
{ "message": "Muitas requisições. Tente de novo em 42 segundos." }
```

A resposta traz o cabeçalho `Retry-After` (segundos até liberar) e os `X-RateLimit-Limit` e `X-RateLimit-Remaining`. O login errado várias vezes tem uma resposta própria, descrita em [Login](#login-post-login), e o "Enviar lembrete agora" também, com o `Retry-After` e a mensagem de quando mandar de novo (veja [Lembretes](#lembretes)).

### Erro de validação (422)

Cada campo traz a lista das suas mensagens. O `message` repete a primeira e conta quantas faltam:

```json
{
  "message": "O campo nome é obrigatório. (e mais 1 erro)",
  "errors": {
    "name": ["O campo nome é obrigatório."],
    "email": ["O campo e-mail é obrigatório."]
  }
}
```

### Registro ou rota inexistente (404)

ID que não existe ou que já foi excluído:

```json
{ "message": "Registro não encontrado." }
```

Rota que não existe (ex.: `GET /api/barbeiros`):

```json
{ "message": "Rota não encontrada." }
```

### Método não permitido (405)

```json
{ "message": "Método não permitido para esta rota." }
```

O cabeçalho `Allow` da resposta lista os métodos que a rota aceita (ex.: `Allow: GET, HEAD, POST`).

### Atualização parcial

`PUT` e `PATCH` aceitam atualização parcial: envie apenas os campos que deseja alterar. Campos de `name`, `email`, `duration`, `price` e `status`, quando enviados, não podem ser vazios.

### Listagens paginadas

`GET /services`, `GET /clients` e `GET /barbers` devolvem uma página por vez, em ordem de nome (e de `id`, quando dois nomes são iguais). `GET /appointments` e `GET /payments` devolvem no mesmo formato, mas com filtros próprios e em ordem de data e horário do atendimento: os agendamentos do mais antigo para o mais novo, e os pagamentos do mais novo para o mais antigo, com os totais do filtro em `totals` (veja [Appointments](#appointments-agendamentos) e [Payments](#payments-pagamentos)). Parâmetros de query, todos opcionais:

| Parâmetro | Regras | Padrão |
|---|---|---|
| `page` | inteiro de 1 a 100000 | `1` |
| `per_page` | inteiro de 1 a 100 | `15` |
| `search` | texto de até 100 caracteres. Procura em `name` e `email`; nos serviços, só em `name` | sem filtro |
| `status` | só em serviços: `active` ou `inactive` | todos |

Valor fora dessas regras devolve `422` (ex.: `page=0`, `page=abc`, `per_page=0`, `per_page=101` ou `status=foo`). Uma página que existe nas regras, mas passa da última (ex.: `page=50` com 4 itens), devolve `200` com `data` vazio.

A resposta vem em três partes: os itens em `data`, os links de navegação em `links` e os números da página em `meta`. Exemplo de `GET /api/services?per_page=2`:

```json
{
  "data": [
    {
      "id": 4,
      "name": "Acabamento",
      "duration": 15,
      "price": "15.00",
      "status": "active",
      "created_at": "2026-11-13T12:00:00.000000Z",
      "updated_at": "2026-11-13T12:00:00.000000Z",
      "deleted_at": null
    },
    {
      "id": 2,
      "name": "Barba",
      "duration": 20,
      "price": "25.00",
      "status": "active",
      "created_at": "2026-11-13T12:00:00.000000Z",
      "updated_at": "2026-11-13T12:00:00.000000Z",
      "deleted_at": null
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/services?per_page=2&page=1",
    "last": "http://localhost:8000/api/services?per_page=2&page=2",
    "prev": null,
    "next": "http://localhost:8000/api/services?per_page=2&page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "links": [
      { "url": null, "label": "&laquo; Anterior", "page": null, "active": false },
      { "url": "http://localhost:8000/api/services?per_page=2&page=1", "label": "1", "page": 1, "active": true },
      { "url": "http://localhost:8000/api/services?per_page=2&page=2", "label": "2", "page": 2, "active": false },
      { "url": "http://localhost:8000/api/services?per_page=2&page=2", "label": "Próxima &raquo;", "page": 2, "active": false }
    ],
    "path": "http://localhost:8000/api/services",
    "per_page": 2,
    "to": 2,
    "total": 4
  }
}
```

| Campo | O que é |
|---|---|
| `data` | itens desta página |
| `links.first` e `links.last` | links da primeira e da última página |
| `links.prev` e `links.next` | links da página anterior e da próxima (`null` quando não existem). Todos os links já levam `per_page`, `search` e `status` |
| `meta.current_page` e `meta.last_page` | página atual e última página |
| `meta.per_page` e `meta.total` | itens por página e total de itens com os filtros aplicados |
| `meta.from` e `meta.to` | posição do primeiro e do último item desta página (`null` quando a página está vazia) |
| `meta.path` | endereço da listagem, sem os parâmetros |
| `meta.links` | botões de paginação prontos, com os rótulos em português |

### Exclusão lógica (soft delete)

`DELETE` não apaga a linha do banco: ele preenche a coluna `deleted_at`. O registro some das listagens e da consulta por ID (que passa a dar `404`), mas continua no banco para o histórico de atendimentos. Não há rota para desfazer a exclusão.

- **Cliente:** os dados pessoais são apagados na exclusão (LGPD). O nome vira `Cliente removido`, o e-mail vira `removido+{id}@barberflow.invalid` e o telefone fica vazio. As observações (`notes`) dos agendamentos dele e dos pagamentos desses agendamentos também ficam vazias, porque são texto livre e podem ter dado pessoal, e não recebem texto novo depois (`422` em `notes`: `O cliente foi excluído: deixe as observações em branco.`).
- **Barbeiro:** só o nome continua, para o histórico mostrar quem atendeu. O e-mail é trocado por `removido+{id}@barberflow.invalid` e o telefone fica vazio.
- **Serviço:** só sai das listas; nada é alterado.

Como o e-mail do cliente ou do barbeiro excluído é trocado, o e-mail original fica livre: dá para cadastrar outra pessoa com ele. O domínio `.invalid` é reservado e nunca recebe mensagem. Por isso o cadastro e a atualização recusam e-mails `@barberflow.invalid` (`422`): se alguém cadastrasse antes o endereço que um excluído vai receber, a exclusão bateria no `unique` do banco.

A validação sozinha não basta no MySQL, que compara texto sem diferenciar acento: um e-mail como `removido+1@barberflow.ínvalid` passa na validação e, para o `unique` do banco, ocupa o endereço reservado do cadastro 1. Para isso não travar a exclusão, ela pergunta ao banco se o endereço já está em uso; se estiver, usa `removido+{id}.{12 letras e números aleatórios}@barberflow.invalid`.

### Datas e fuso horário

A aplicação roda no fuso `America/Sao_Paulo` (variável `APP_TIMEZONE`). `created_at`, `updated_at` e `deleted_at` saem no padrão ISO 8601 em UTC (o `Z` no fim): `2026-11-13T15:00:00.000000Z` é meio-dia em Brasília.

Na agenda é diferente: `date`, `start_time` e `end_time` são a data e o horário de Brasília, sem conversão (`"date": "2026-11-16"` e `"start_time": "10:00:00"` são 10h em São Paulo). O "agora" das regras (horário que já passou, janela de cancelamento) vem do relógio da aplicação, nesse mesmo fuso.

---

## Autenticação

Há dois jeitos de entrar, e os dois usam o Laravel Sanctum:

| Quem | Como entra | Como se identifica nas chamadas |
|---|---|---|
| Postman, Insomnia, `curl` e outros sistemas | `POST /api/login` devolve um token | Cabeçalho `Authorization: Bearer <token>` |
| As telas do BarberFlow (navegador) | Formulário de login em `/login` (sessão) | Cookie da sessão, com o token CSRF no `X-CSRF-TOKEN`. O Sanctum reconhece as telas pelo `Referer`, que precisa estar em `SANCTUM_STATEFUL_DOMAINS` (padrão: `localhost` e o host do `APP_URL`) |

O token nunca fica guardado no navegador: as telas só usam a sessão.

### Login (`POST /login`)

| Campo | Obrigatório | Regras |
|---|---|---|
| `email` | sim | e-mail válido |
| `password` | sim | texto |
| `device_name` | não | nome do token (até 255 caracteres), para reconhecer de onde veio. Padrão: `api` |

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"<senha>"}'
```

Resposta `200`:

```json
{
  "token": "1|barberflow_<texto aleatório>",
  "token_type": "Bearer",
  "expires_at": "2026-11-13T23:00:00.000000Z",
  "user": {
    "id": 1,
    "name": "Test User",
    "email": "test@example.com",
    "role": "admin"
  }
}
```

- O token começa com o prefixo `barberflow_` (`SANCTUM_TOKEN_PREFIX`), depois do número que vem antes do `|`. O prefixo ajuda ferramentas de varredura a achar um token que vazou num repositório.
- Ele vale `SANCTUM_EXPIRATION` minutos (padrão 480, um expediente). O `expires_at` diz quando vence, em UTC. Vencido, a API responde `401` e é preciso fazer login de novo.
- `role` é o perfil: `admin`, `barber` ou `client`.

Recusas (`422`, sempre no campo `email`, sem dizer se o erro foi no e-mail ou na senha):

| Situação | Mensagem |
|---|---|
| E-mail ou senha errados | `E-mail ou senha incorretos.` |
| Conta sem perfil, barbeiro excluído ou cliente com o cadastro excluído | `Esta conta não tem acesso.` |

Depois de 5 recusas no mesmo minuto para o mesmo e-mail e o mesmo IP (`LOGIN_RATE_LIMIT`), o login fica bloqueado até o minuto passar, mesmo com a senha certa, e a resposta vira `429`:

```json
{
  "message": "Muitas tentativas de login. Tente de novo em 58 segundos.",
  "errors": {
    "email": ["Muitas tentativas de login. Tente de novo em 58 segundos."]
  }
}
```

Como nos outros `429`, a resposta traz `Retry-After` (os mesmos segundos da mensagem), `X-RateLimit-Limit` (as 5 recusas) e `X-RateLimit-Remaining: 0`.

Um login certo zera essa contagem. Além dela, cada IP tem até 20 tentativas de login por minuto, com qualquer e-mail (`LOGIN_IP_RATE_LIMIT`).

### Usando o token

```bash
curl http://localhost:8000/api/services \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|barberflow_<texto aleatório>"
```

Nos exemplos das próximas seções, `$TOKEN` é o token devolvido pelo login (no Bash: `TOKEN='1|barberflow_...'`).

### Logout (`POST /logout`)

Apaga o token usado na chamada (os outros tokens da pessoa continuam valendo). Resposta `204`. Chamado pelas telas, encerra a sessão do navegador.

### Usuário logado (`GET /user`)

```json
{
  "id": 1,
  "name": "Test User",
  "email": "test@example.com",
  "role": "admin",
  "email_verified_at": "2026-11-13T15:00:00.000000Z",
  "barber_id": null,
  "client_id": null
}
```

`barber_id` traz o cadastro de barbeiro ligado ao login (perfil `barber`) e `client_id`, o cadastro de cliente ligado à conta (perfil `client`). Sem cadastro ligado, vêm `null`.

### Troca de senha (`PUT /user/password`)

| Campo | Obrigatório | Regras |
|---|---|---|
| `current_password` | sim | a senha atual |
| `password` | sim | mínimo de 8 caracteres |
| `password_confirmation` | sim | igual a `password` |

Resposta `204`. Os outros tokens da pessoa param de valer e as outras sessões abertas nas telas caem na próxima requisição; o token (ou a sessão) desta chamada continua. Senha atual errada dá `422` com `errors.current_password`: `A senha está incorreta.`

### Conta de cliente

O cliente cria a própria conta. Admin e barbeiro não se cadastram: o admin inicial vem do `AdminUserSeeder` (veja o README) e o login do barbeiro é criado pelo admin no cadastro de barbeiros (campo `password`).

**Cadastro (`POST /register`)**, aberto, com até 5 cadastros por minuto por IP:

| Campo | Obrigatório | Regras |
|---|---|---|
| `name` | sim | até 255 caracteres |
| `email` | sim | e-mail válido, até 255 caracteres, que ainda não tenha conta. Não aceita o domínio `barberflow.invalid` |
| `phone` | não | até 20 caracteres |
| `password` | sim | mínimo de 8 caracteres |
| `password_confirmation` | sim | igual a `password` |

Resposta `201`:

```json
{ "message": "Conta criada. Confirme seu e-mail pelo link que enviamos para começar a usar a conta." }
```

A conta nasce com o perfil `client` (um `role` mandado no corpo é ignorado) e com o e-mail ainda não confirmado. O BarberFlow manda um e-mail com o link de confirmação, que vale 60 minutos.

Até confirmar, a conta entra (login e token funcionam), mas só usa as rotas da própria conta: `/user`, `/user/password`, `/logout` e o reenvio do link. As outras, como `/services` e `/barbers`, respondem `403` (`Confirme seu e-mail para continuar.`), e assim quem cria uma conta com o e-mail de outra pessoa não vê nada antes de provar que o e-mail é dele.

**Confirmação do e-mail.** O link do e-mail abre nas telas (`/email/verify/{id}/{hash}`, assinado). Quem não está logado entra primeiro e depois o link é confirmado. Ao confirmar:

- se já existe um cliente com o mesmo e-mail, sem conta, a conta é ligada a ele, e o cliente passa a ver o histórico que a barbearia já tinha;
- se não existe, nasce um cadastro de cliente com o nome, o e-mail e o telefone da conta.

A ligação só acontece depois da confirmação, porque é ela que prova que a pessoa é dona do e-mail. Sem isso, qualquer um veria o histórico de outra pessoa só digitando o e-mail dela.

Fora do ambiente local, os links que vão por e-mail (confirmação e "esqueci minha senha") sempre começam pelo `APP_URL`, seja qual for o `Host` da requisição que pediu o e-mail.

**Reenviar o link (`POST /email/verification-notification`)**, com login, até 6 vezes por minuto: `202` com `{"message": "Enviamos um novo link de confirmação para o seu e-mail."}`, ou `200` com `{"message": "Seu e-mail já está confirmado."}`.

### Esqueci minha senha

**Pedir o link (`POST /forgot-password`)**, aberto, com o campo `email`. A resposta é sempre a mesma, com e-mail cadastrado ou não, para ninguém usar esta rota para descobrir quem tem conta. Vale também com o servidor de e-mail fora do ar ou recusando o endereço: a falha, que só acontece com um e-mail cadastrado, vai para o log da aplicação sem o endereço, e a resposta não muda:

```json
{ "message": "Se o e-mail estiver cadastrado, você vai receber o link para redefinir a senha." }
```

O link do e-mail abre a tela `/reset-password/{token}?email=...` e vale 60 minutos. O mesmo e-mail só recebe um link novo a cada 60 segundos.

**Redefinir (`POST /reset-password`)**, aberto, com `token` (o do link), `email`, `password` e `password_confirmation`. Resposta `200` com `{"message": "Sua senha foi redefinida."}`. A redefinição encerra todos os logins da pessoa: apaga os tokens, as sessões das telas e o "lembrar de mim". Token errado, usado ou vencido, ou e-mail sem conta, dão a mesma resposta `422`, no campo `email`: `Este link para redefinir a senha é inválido ou já expirou.`

Conta de cliente que ainda não confirmou o e-mail: a redefinição também descarta o nome e o telefone digitados no cadastro da conta (o nome vira a parte do e-mail antes do `@` e o telefone fica vazio). Quem criou a conta pode não ser o dono do e-mail, e quem redefine acabou de provar que é. Assim, o nome e o telefone de outra pessoa não vão parar no cadastro de cliente quando o dono confirmar o e-mail.

As duas rotas aceitam até 5 pedidos por minuto para o mesmo e-mail e o mesmo IP.

---

## Perfis e permissões

Cada usuário tem um perfil (`users.role`): `admin`, `barber` (barbeiro) ou `client` (cliente). Usuário sem perfil não entra. As regras ficam nas Policies de `app/Policies`:

| Recurso | Admin | Barbeiro | Cliente |
|---|---|---|---|
| Serviços | tudo | ver, inclusive os inativos | ver só os ativos (um inativo dá `404`, como se não existisse; na agenda, inativo ou excluído dá a mesma mensagem de um id que não existe) |
| Barbeiros | tudo, inclusive criar e redefinir o login pelo campo `password` | ver, com e-mail, telefone e `has_login` | ver só `id` e `name`, para escolher com quem agendar. A busca olha só o nome |
| Clientes | tudo | ver, cadastrar e editar (não exclui) | ver e editar só o próprio cadastro (nome e telefone). Não lista os outros |
| Agendamentos | tudo | ver, agendar, remarcar e mudar o status só na própria agenda | agendar para si (até 3 agendamentos ativos), ver os próprios e cancelar até 2 horas antes. Não remarca |
| Horários livres | sim | sim | sim |
| Lembrete ("Enviar lembrete agora") | de qualquer agendamento | só dos agendamentos da própria agenda | não, nem do próprio horário |
| Pagamentos | tudo: registrar (com ou sem o prêmio da fidelidade), corrigir, marcar como pago e estornar | registrar (com ou sem o prêmio) e ver os da própria agenda e marcar como pago um pendente deles. Não corrige nem estorna | ver os próprios |
| Fidelidade | ver a de qualquer cliente | ver a de qualquer cliente | ver só a própria |
| Dashboard | ver | não | não |
| Conta (`/user`, `/user/password`, `/logout`) | sim | sim | sim |

- Ação fora da tabela dá `403` (ex.: barbeiro em `POST /services`, cliente em `GET /clients`, barbeiro agendando na agenda de outro, cliente agendando para outra pessoa, barbeiro mandando o lembrete de um agendamento de outro barbeiro, cliente vendo a fidelidade de outro, barbeiro estornando um pagamento ou abrindo o dashboard).
- Serviços, barbeiros, clientes, agendamentos, lembretes, fidelidade e pagamentos exigem o e-mail confirmado. A conta de cliente que ainda não confirmou recebe `403` com `Confirme seu e-mail para continuar.` (admin e barbeiro já nascem com o e-mail confirmado).
- O cliente que manda `email` ao editar o próprio cadastro, mesmo vazio ou `null`, recebe `422` com `O campo e-mail não é permitido.`: o e-mail do cadastro fica com a barbearia, porque foi por ele que a conta foi ligada.
- Os detalhes por perfil estão em [Appointments](#appointments-agendamentos), [Lembretes](#lembretes), [Payments](#payments-pagamentos), [Fidelidade](#fidelidade) e [Dashboard](#dashboard).

---

## Limites de requisição

Os valores ficam em `config/barberflow.php` e os que têm variável podem mudar pelo `.env`. Passou do limite, a resposta é `429` com `Retry-After`.

| O quê | Limite | Contado por | Variável |
|---|---|---|---|
| Toda a API | 120 por minuto | usuário logado, ou IP sem login | `API_RATE_LIMIT` |
| Login com senha errada | 5 recusas por minuto | e-mail + IP | `LOGIN_RATE_LIMIT` |
| Tentativas de login | 20 por minuto | IP | `LOGIN_IP_RATE_LIMIT` |
| Cadastro de conta de cliente | 5 por minuto | IP | (fixo) |
| Esqueci e redefinir a senha | 5 por minuto | e-mail + IP | (fixo) |
| Reenvio do e-mail de confirmação | 6 por minuto | usuário | (fixo) |
| "Enviar lembrete agora" | 1 a cada 10 minutos, contando o lembrete do comando e mesmo depois de remarcar | agendamento | (fixo, `resend_minutes`) |
| "Enviar lembrete agora" | 10 por minuto | usuário | (fixo) |
| `GET /health` | sem limite | | |

Atrás de um balanceador de carga, defina `TRUSTED_PROXIES` (ex.: a faixa da rede do ALB). Sem ele, todo visitante parece vir do IP do balanceador e todos dividem a mesma contagem. Com o `APP_URL` em `https`, ele também é preciso para o link de confirmação de e-mail valer: o link é assinado com o endereço `https`, e o app só sabe que a requisição veio por `https` pelo cabeçalho do balanceador.

---

## Health (saúde da aplicação)

`GET /health` confere se a aplicação alcança o banco e o cache. Serve para monitoramento, deploy e testes de carga. O `/up` (fora do `/api`) continua só dizendo que o PHP responde, porque é ele que o balanceador de carga consulta.

Resposta `200` com tudo no ar:

```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "cache": "ok"
  }
}
```

Resposta `503` quando algo falha (aqui, o banco):

```json
{
  "status": "falhou",
  "checks": {
    "database": "falhou",
    "cache": "ok"
  }
}
```

A resposta só diz `ok` ou `falhou`. O motivo da falha vai para o log da aplicação, nunca para a resposta.

A rota é aberta (sem login) e fica fora do limite de requisições por minuto da API (`withoutMiddleware('throttle:api')` em `routes/api.php`): o monitoramento e o deploy consultam o `/health` sem token e sem receber `429`.

```bash
curl -i http://localhost:8000/api/health
```

---

## Services (serviços)

Serviços oferecidos pela barbearia (corte, barba, combo etc.).

**Quem pode:** o admin faz tudo; o barbeiro só vê, inclusive os inativos; o cliente vê só os ativos (a lista dele ignora o filtro `status`, e um serviço inativo dá `404`).

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `duration` | integer | sim | duração em **minutos**, de 5 a 480 |
| `price` | decimal | sim | de 0 a 999999.99. Na resposta, sempre texto com 2 casas: `"35.00"` |
| `status` | string | não | `active` ou `inactive`. Sem ele, o serviço nasce `active` |

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/services` | Lista os serviços (filtros: `search`, `status`, `per_page`, `page`) | `200` + página |
| POST | `/services` | Cria um serviço | `201` + serviço criado |
| GET | `/services/{id}` | Mostra um serviço | `200` + serviço |
| PUT/PATCH | `/services/{id}` | Atualiza um serviço | `200` + serviço atualizado |
| DELETE | `/services/{id}` | Exclui um serviço (exclusão lógica). Com agendamento futuro ativo, `409` ([veja](#excluir-cadastro-com-agendamento-futuro-409)) | `204` |

### Exemplos

**Criar serviço** (sem `status`, que fica `active`)

```bash
curl -X POST http://localhost:8000/api/services \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Corte de cabelo","duration":30,"price":35}'
```

Resposta `201`:

```json
{
  "name": "Corte de cabelo",
  "duration": 30,
  "price": "35.00",
  "status": "active",
  "updated_at": "2026-11-13T15:00:00.000000Z",
  "created_at": "2026-11-13T15:00:00.000000Z",
  "id": 1
}
```

`price` sai sempre como texto com 2 casas (`"35.00"`), no SQLite e no MySQL, para não perder centavos com número quebrado. Para fazer conta, converta (`Number("35.00")` no JavaScript).

**Listar só os serviços ativos com "corte" no nome**

```bash
curl "http://localhost:8000/api/services?status=active&search=corte" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

**Valor fora do limite** (`422`):

```json
{
  "message": "O campo duração não pode ser maior que 480. (e mais 1 erro)",
  "errors": {
    "duration": ["O campo duração não pode ser maior que 480."],
    "price": ["O campo preço não pode ser maior que R$ 999.999,99."]
  }
}
```

**Atualizar parcialmente**

```bash
curl -X PATCH http://localhost:8000/api/services/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"price":45}'
```

**Excluir**

```bash
curl -X DELETE http://localhost:8000/api/services/1 \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Resposta: `204 No Content`.

---

## Clients (clientes)

**Quem pode:** o admin faz tudo; o barbeiro vê, cadastra e edita, mas não exclui; o cliente vê e edita só o próprio cadastro (nome e telefone) e não lista os outros (`403`).

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `email` | string | sim | e-mail válido, até 255 caracteres e **único** na tabela `clients`. Não aceita o domínio `barberflow.invalid`, reservado para cadastros excluídos. O cliente não pode mudar o próprio nem mandar o campo, mesmo vazio (`422`: `O campo e-mail não é permitido.`) |
| `phone` | string | não | até 20 caracteres |

A resposta traz também `has_login` (`true` quando o cliente tem conta para entrar no sistema). A conta é ligada ao cadastro quando o cliente confirma o e-mail (veja [Conta de cliente](#conta-de-cliente)).

Na listagem (`GET /clients`), cada cliente traz também `loyalty`, o resumo da fidelidade dele (visitas, progresso e prêmios), contado na própria consulta da página, sem uma consulta por cliente. Os campos estão em [Fidelidade](#fidelidade).

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/clients` | Lista os clientes, cada um com a fidelidade em `loyalty` (filtros: `search`, `per_page`, `page`) | `200` + página |
| POST | `/clients` | Cadastra um cliente | `201` + cliente criado |
| GET | `/clients/{id}` | Mostra um cliente | `200` + cliente |
| PUT/PATCH | `/clients/{id}` | Atualiza um cliente | `200` + cliente atualizado |
| DELETE | `/clients/{id}` | Exclui o cliente e apaga os dados pessoais dele. Com agendamento futuro ativo, `409` ([veja](#excluir-cadastro-com-agendamento-futuro-409)) | `204` |
| GET | `/clients/{id}/loyalty` | Fidelidade do cliente: visitas, progresso e prêmios ([veja](#fidelidade)) | `200` + resumo |

### Exemplos

**Cadastrar cliente**

```bash
curl -X POST http://localhost:8000/api/clients \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Carlos Silva","email":"carlos@example.com","phone":"67999990000"}'
```

Resposta `201`:

```json
{
  "name": "Carlos Silva",
  "email": "carlos@example.com",
  "phone": "67999990000",
  "updated_at": "2026-11-13T15:00:00.000000Z",
  "created_at": "2026-11-13T15:00:00.000000Z",
  "id": 1,
  "has_login": false
}
```

**Buscar por nome ou e-mail**

```bash
curl "http://localhost:8000/api/clients?search=silva&per_page=10" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

**E-mail duplicado** (`422`):

```json
{
  "message": "Este e-mail já está cadastrado.",
  "errors": {
    "email": ["Este e-mail já está cadastrado."]
  }
}
```

Na atualização, o e-mail do próprio registro é ignorado na checagem de unicidade, então enviar o mesmo e-mail em um `PUT` não gera erro.

**Excluir** (`DELETE /clients/1`, resposta `204`): o cliente sai das listas e, no banco, a linha fica assim:

```json
{
  "id": 1,
  "name": "Cliente removido",
  "email": "removido+1@barberflow.invalid",
  "phone": null,
  "deleted_at": "2026-11-13T15:30:00.000000Z"
}
```

Depois disso, `carlos@example.com` pode ser cadastrado de novo, como um cliente novo. Se o cliente tinha conta, ela é apagada junto, com os tokens e as sessões abertas: ele não entra mais. Os agendamentos dele continuam no histórico, com `"name": "Cliente removido"` e `"notes": null`.

---

## Barbers (barbeiros)

**Quem pode:** só o admin cadastra, edita e exclui. O barbeiro vê tudo; o cliente vê só `id` e `name` de cada barbeiro, e a busca dele olha só o nome.

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `email` | string | sim | e-mail válido, até 255 caracteres e **único** na tabela `barbers`. Também não pode ser o e-mail de outro usuário, porque vira o login do barbeiro. Não aceita o domínio `barberflow.invalid`, reservado para cadastros excluídos |
| `phone` | string | não | até 20 caracteres |
| `password` | string | não | mínimo de 8 caracteres, sem ser só de espaços (`422`: `O campo senha não pode ter só espaços.`). Cria o login do barbeiro (perfil `barber`, com o nome e o e-mail do cadastro) ou troca a senha do login que já existe. A troca encerra os logins abertos dele. Nunca aparece na resposta |

A resposta traz também `has_login` (`true` quando o barbeiro tem login). O login em si (id e perfil) nunca aparece na resposta, nem no cadastro nem na atualização. Mudou o nome ou o e-mail do cadastro, o login acompanha.

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/barbers` | Lista os barbeiros (filtros: `search`, `per_page`, `page`) | `200` + página |
| POST | `/barbers` | Cadastra um barbeiro | `201` + barbeiro criado |
| GET | `/barbers/{id}` | Mostra um barbeiro | `200` + barbeiro |
| PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro | `200` + barbeiro atualizado |
| DELETE | `/barbers/{id}` | Exclui o barbeiro (mantém o nome, troca o e-mail e apaga o telefone). Com agendamento futuro ativo, `409` ([veja](#excluir-cadastro-com-agendamento-futuro-409)) | `204` |

### Exemplos

**Cadastrar barbeiro com login** (sem `password`, o barbeiro fica sem login e `has_login` sai `false`)

```bash
curl -X POST http://localhost:8000/api/barbers \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"João Barbeiro","email":"joao@barberflow.com","phone":"67988880000","password":"<senha do barbeiro>"}'
```

Resposta `201`:

```json
{
  "name": "João Barbeiro",
  "email": "joao@barberflow.com",
  "phone": "67988880000",
  "updated_at": "2026-11-13T15:00:00.000000Z",
  "created_at": "2026-11-13T15:00:00.000000Z",
  "id": 1,
  "has_login": true
}
```

O barbeiro entra com `joao@barberflow.com` e a senha definida pelo admin. Para redefinir a senha dele, mande só o `password` num `PATCH /barbers/{id}`.

**Visto pelo cliente** (`GET /barbers`, item de `data`):

```json
{ "id": 1, "name": "João Barbeiro" }
```

Na exclusão, o barbeiro sai das listas, o nome fica no banco, o telefone é apagado e o e-mail vira `removido+{id}@barberflow.invalid`, liberando `joao@barberflow.com` para outro cadastro. O login dele é apagado junto, com os tokens e as sessões abertas.

---

## Appointments (agendamentos)

Horário de um cliente com um barbeiro para um serviço. As regras ficam em `App\Services\AppointmentScheduler` e os status em `App\Enums\AppointmentStatus`.

**Quem pode:**

| Ação | Admin | Barbeiro | Cliente |
|---|---|---|---|
| Listar e ver | todos | só os da agenda dele | só os próprios |
| Agendar (`POST`) | para qualquer cliente, com qualquer barbeiro | só na própria agenda, para qualquer cliente. Sem `barber_id`, vale o dele | só para si, com qualquer barbeiro, até `CLIENT_MAX_ACTIVE_APPOINTMENTS` agendamentos ativos de hoje em diante (padrão 3). Sem `client_id`, vale o dele |
| Remarcar (`PUT`/`PATCH`) | sim | só os dele, sem passar para outro barbeiro | não: cancela e agenda de novo |
| Mudar o status | qualquer transição válida | qualquer transição válida, nos dele | só cancelar, até `CLIENT_CANCEL_MIN_HOURS` horas antes (padrão 2) |
| Horários livres | sim | sim | sim |
| Enviar lembrete agora ([veja](#lembretes)) | sim | só nos dele | não (`403`, mesmo no próprio horário) |

`barber_id` de outro barbeiro (no `POST` do barbeiro) ou `client_id` de outro cliente (no `POST` do cliente) dá `403`. Na listagem, os filtros não abrem a agenda dos outros: `barber_id` de outro barbeiro devolve a lista vazia, e não `403`.

O limite do cliente conta os agendamentos agendados, confirmados ou em atendimento de hoje em diante; cancelado, faltou, concluído e os dias que passaram não contam. No limite, o `POST` do cliente dá `422` em `client_id`: `Você já tem 3 horários marcados, o máximo para agendar pela sua conta. Cancele um horário para agendar outro, ou fale com a barbearia.` A barbearia agenda para o cliente sem esse limite.

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `client_id` | integer | sim (o cliente pode deixar de fora) | cliente que existe e não foi excluído |
| `barber_id` | integer | sim (o barbeiro pode deixar de fora) | barbeiro que existe e não foi excluído |
| `service_id` | integer | sim | serviço ativo e não excluído. Para o cliente, inativo ou excluído dá a mesma mensagem de um id que não existe (`O valor escolhido no campo serviço é inválido.`) |
| `date` | string | sim | `Y-m-d` (ex.: `2026-11-16`): dia de expediente, de hoje até `BOOKING_DAYS_AHEAD` dias à frente |
| `start_time` | string | sim | `H:i` ou `H:i:s` (ex.: `10:00`): uma fatia do expediente que ainda não passou |
| `notes` | string | não | até 1000 caracteres. Quem vê o agendamento vê as observações, inclusive o cliente. Excluir o cliente apaga as observações dos agendamentos dele, e eles não recebem texto novo depois |

O servidor preenche o resto, e ignora esses campos quando vêm no corpo:

| Campo da resposta | O que é |
|---|---|
| `end_time` | início + duração do serviço, em `H:i:s` |
| `price` | cópia do preço do serviço no momento do agendamento, como texto com 2 casas (`"35.00"`). Mudar o preço do serviço depois não muda os agendamentos que já existem |
| `status` e `status_label` | o status (começa em `scheduled`) e o nome dele em português |
| `client`, `barber` e `service` | só `id` e `name` de cada um, sem e-mail e telefone. Continuam aparecendo depois que o cadastro é excluído (o cliente, anonimizado) |
| `payment` | o pagamento do atendimento, só com `id`, `status` e `status_label` (ex.: `{"id": 9, "status": "paid", "status_label": "Pago"}`), ou `null` quando ainda não há pagamento. Valor e forma ficam em [Payments](#payments-pagamentos) |
| `reminder_sent_at` | quando saiu o lembrete por e-mail, em UTC (ISO 8601), ou `null` enquanto não saiu. Só o servidor grava: o comando `appointments:send-reminders` e o "Enviar lembrete agora" (veja [Lembretes](#lembretes)). Remarcar a data ou o horário volta a `null`, para o lembrete do horário novo sair |

### Regras de horário

Os valores ficam em `config/barberflow.php` e mudam pelo `.env` (`BUSINESS_*`):

| Regra | Padrão | Variável | Recusa (`422`) |
|---|---|---|---|
| Só em dia de atendimento (1 é segunda, 7 é domingo) | segunda a sábado | `BUSINESS_DAYS=1,2,3,4,5,6` | `date`: `A barbearia não abre nesse dia.` |
| Nada no passado (data e horário de Brasília) | | | `date`: `Essa data já passou.` ou `start_time`: `Esse horário já passou.` |
| Até um limite de dias à frente, em qualquer perfil | 60 dias (hoje + 60 é a última data) | `BOOKING_DAYS_AHEAD` | `date`: `Só é possível agendar com até 60 dias de antecedência (até 15/01/2027).` |
| Começar e terminar dentro do expediente | 09:00 às 19:00 | `BUSINESS_OPENING` e `BUSINESS_CLOSING` | `start_time`: `O atendimento precisa começar e terminar dentro do expediente, das 09:00 às 19:00.` |
| Começar numa fatia, contada a partir da abertura | de 30 em 30 minutos | `BUSINESS_SLOT_MINUTES` | `start_time`: `Escolha um horário de 30 em 30 minutos, a partir das 09:00.` |
| O barbeiro sem outro atendimento no mesmo período | | | `start_time`: `Horário indisponível para este barbeiro.` |
| O cliente sem outro atendimento no mesmo período, com qualquer barbeiro | | | `client_id`: `O cliente já tem um horário marcado nesse período.` |
| Serviço ativo | | | `service_id`: `Este serviço está inativo e não pode ser agendado.` (para o cliente, a mensagem de id que não existe) |
| Cadastros não excluídos | | | `client_id`, `barber_id` ou `service_id`: `Este cliente foi excluído.` (e o mesmo para o barbeiro e o serviço; para o cliente, o serviço excluído dá a mensagem de id que não existe) |
| O cliente com no máximo N agendamentos ativos (só no `POST` dele) | 3 | `CLIENT_MAX_ACTIVE_APPOINTMENTS` | `client_id`: `Você já tem 3 horários marcados, o máximo para agendar pela sua conta. Cancele um horário para agendar outro, ou fale com a barbearia.` |

- A sobreposição conta o intervalo `[início, fim)`: um atendimento pode começar na hora exata em que o outro termina (`10:00 às 10:30` e `10:30 às 11:00` convivem).
- Cancelado e faltou não ocupam o horário. Os outros status ocupam, inclusive o concluído.
- O barbeiro e o cliente ocupados podem vir juntos na mesma resposta, cada um no seu campo.
- Dois pedidos para o mesmo barbeiro ou o mesmo cliente ao mesmo tempo passam um de cada vez, e o segundo já enxerga o primeiro. Pedidos com outro barbeiro e outro cliente não esperam um pelo outro (veja a concorrência em [`ARQUITETURA.md`](ARQUITETURA.md#agenda)).

### Status e transições

| Status | Nome | Pode ir para | Observação |
|---|---|---|---|
| `scheduled` | Agendado | `confirmed`, `in_progress`, `cancelled`, `no_show` | status de todo agendamento novo; pode ser remarcado |
| `confirmed` | Confirmado | `in_progress`, `cancelled`, `no_show` | pode ser remarcado |
| `in_progress` | Em atendimento | `completed` | só a partir do horário de início |
| `completed` | Concluído | (final) | só a partir do horário de início |
| `cancelled` | Cancelado | (final) | libera o horário |
| `no_show` | Faltou | (final) | só a partir do horário de início; libera o horário |

Recusas (`422`, no campo `status`):

| Situação | Mensagem |
|---|---|
| Transição fora da tabela | `Não é possível mudar de Concluído para Cancelado.` |
| Em atendimento, concluído ou faltou antes do início | `Só é possível mudar para Em atendimento depois do horário de início.` |
| Cliente cancelando depois da janela | `Cancelamento só até 2 horas antes do horário.` |
| Remarcar com outro status além de agendado ou confirmado | `Só é possível remarcar um agendamento com status Agendado ou Confirmado.` |

A barbearia (admin e barbeiro) cancela a qualquer momento; a janela vale só para o cliente.

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/appointments` | Lista em ordem de data e horário (filtros abaixo) | `200` + página |
| POST | `/appointments` | Agenda | `201` + agendamento |
| GET | `/appointments/{id}` | Mostra um agendamento | `200` + agendamento |
| PUT/PATCH | `/appointments/{id}` | Remarca (`date`, `start_time`, `barber_id`, `service_id`) e muda as `notes` | `200` + agendamento |
| PATCH | `/appointments/{id}/status` | Muda o status (`{"status": "confirmed"}`) | `200` + agendamento |
| POST | `/appointments/{id}/reminder` | Envia o lembrete por e-mail na hora, sem corpo ([veja](#lembretes)) | `200` + agendamento |
| GET | `/barbers/{id}/available-slots` | Horários livres do barbeiro no dia | `200` + lista |

Não existe `DELETE /appointments/{id}` (`405`): cancelar é mudar o status, e o agendamento fica no histórico.

**Remarcar:**
- só com o status agendado ou confirmado;
- quando a data, o horário, o barbeiro ou o serviço mudam, as regras de horário rodam de novo, sem contar o próprio agendamento, e o término é recalculado. Mudar só as `notes` não confere o horário;
- o preço só muda quando o serviço muda;
- mudar a data ou o horário volta o `reminder_sent_at` a `null`: o lembrete que saiu falava do horário antigo, e o comando manda o do horário novo. Trocar só o barbeiro, o serviço ou as observações não mexe nele;
- `client_id` e `status` mandados no corpo são ignorados, sem erro: o cliente não muda, e o status tem rota própria;
- no agendamento de um cliente excluído (um passado que ficou como agendado), `notes` preenchido dá `422` em `notes`: `O cliente foi excluído: deixe as observações em branco.` A exclusão apagou as observações (LGPD), e `notes` vazio ou `null` continua aceito;
- se outra pessoa trocou o barbeiro ou o serviço do mesmo agendamento no mesmo instante, a resposta é `409` com `O agendamento mudou enquanto você editava. Recarregue e tente de novo.`

### Filtros da listagem

| Parâmetro | Regras |
|---|---|
| `date` | `Y-m-d`: só os desse dia |
| `from` e `to` | `Y-m-d`: um período, com os dois dias incluídos. `to` não pode ser antes de `from` |
| `barber_id` e `client_id` | inteiro |
| `status` | um dos 6 status da tabela acima |
| `without_payment` | `1`: só os atendimentos concluídos que ainda não têm pagamento registrado, os que a tela de Pagamentos lista no "Registrar pagamento". Pagamento pendente ou estornado conta como registrado. `0`, ou sem o filtro, devolve a lista de sempre. Outro valor (ex.: `talvez`) dá `422`: `O campo sem pagamento deve ser verdadeiro ou falso.` |
| `page` e `per_page` | como nas outras listagens (`per_page` de 1 a 100, padrão 15) |

A listagem de agendamentos não tem `search`. A ordem é data, horário e `id`. Valor fora das regras dá `422` (ex.: `date=16/11/2026`, `status=foo`, `to` antes de `from`). Os filtros se combinam: `without_payment=1&barber_id=2` lista os concluídos sem pagamento do barbeiro 2.

### Horários livres

`GET /barbers/{id}/available-slots?date=2026-11-16&service_id=1`:

| Parâmetro | Obrigatório | Regras |
|---|---|---|
| `date` | sim | `Y-m-d` |
| `service_id` | sim | serviço ativo e não excluído (`422` em `service_id` se não for; para o cliente, com a mesma mensagem de um id que não existe) |
| `appointment_id` | não | ao remarcar, o id do agendamento: o horário dele conta como livre. Só para quem pode remarcá-lo (admin e o barbeiro dele); os outros, inclusive o cliente, recebem `403` |

Resposta `200`, com os inícios livres em `H:i`:

```json
{ "data": ["09:00", "09:30", "10:30", "11:00", "11:30"] }
```

- Entra só o horário em que o serviço inteiro cabe no expediente e não cruza outro agendamento do barbeiro.
- Hoje, só os horários que ainda não passaram. Dia que já passou, sem expediente ou depois do limite de `BOOKING_DAYS_AHEAD` dias devolve `{"data": []}`.
- Barbeiro excluído dá `404`.
- A lista olha só a agenda do barbeiro. Se o cliente já tem horário com outro barbeiro no mesmo período, o `POST` recusa com o erro em `client_id`.

### Exemplos

**Agendar** (como admin):

```bash
curl -X POST http://localhost:8000/api/appointments \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"client_id":1,"barber_id":1,"service_id":1,"date":"2026-11-16","start_time":"10:00","notes":"Primeira vez na barbearia"}'
```

Resposta `201`:

```json
{
  "status": "scheduled",
  "reminder_sent_at": null,
  "client_id": 1,
  "barber_id": 1,
  "service_id": 1,
  "date": "2026-11-16",
  "start_time": "10:00:00",
  "end_time": "10:30:00",
  "price": "35.00",
  "notes": "Primeira vez na barbearia",
  "updated_at": "2026-11-13T15:00:00.000000Z",
  "created_at": "2026-11-13T15:00:00.000000Z",
  "id": 1,
  "status_label": "Agendado",
  "client": { "id": 1, "name": "Carlos Silva" },
  "barber": { "id": 1, "name": "João Barbeiro" },
  "service": { "id": 1, "name": "Corte de cabelo" },
  "payment": null
}
```

O cliente agenda para si sem mandar `client_id` (e o barbeiro, na própria agenda, sem `barber_id`).

**Horário ocupado** (`422`):

```json
{
  "message": "Horário indisponível para este barbeiro. (e mais 1 erro)",
  "errors": {
    "start_time": ["Horário indisponível para este barbeiro."],
    "client_id": ["O cliente já tem um horário marcado nesse período."]
  }
}
```

**Listar a agenda de um dia de um barbeiro:**

```bash
curl "http://localhost:8000/api/appointments?date=2026-11-16&barber_id=1&per_page=100" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

A resposta é paginada como as outras listagens (`data`, `links` e `meta`), e cada item de `data` é um agendamento como o do exemplo acima.

**Remarcar** para outro horário:

```bash
curl -X PATCH http://localhost:8000/api/appointments/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"start_time":"11:00"}'
```

Resposta `200` com o agendamento: `start_time` `"11:00:00"` e `end_time` `"11:30:00"`.

**Mudar o status:**

```bash
curl -X PATCH http://localhost:8000/api/appointments/1/status \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"status":"confirmed"}'
```

Resposta `200` com o agendamento: `"status": "confirmed"` e `"status_label": "Confirmado"`.

**Horários livres:**

```bash
curl "http://localhost:8000/api/barbers/1/available-slots?date=2026-11-16&service_id=1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

### Excluir cadastro com agendamento futuro (409)

Cliente, barbeiro ou serviço com agendamento de hoje em diante ainda ativo (agendado, confirmado ou em atendimento) não pode ser excluído. O `DELETE` responde `409` e não muda nada:

```json
{ "message": "Este cadastro tem agendamentos futuros. Cancele ou remarque antes de excluir." }
```

Com só agendamentos passados, cancelados ou com falta, a exclusão segue normalmente, e o histórico continua mostrando o nome (do cliente, anonimizado). As telas de Clientes, Barbeiros e Serviços mostram essa mensagem num aviso.

---

## Lembretes

Lembrete por e-mail do horário marcado, mandado na hora, sem fila. As regras ficam em `App\Services\ReminderSender`, e o e-mail em `App\Mail\AppointmentReminder` (Markdown, sempre em português).

**Quem recebe:** o cliente de um agendamento agendado ou confirmado que ainda não começou. Nunca o cliente excluído, nem um e-mail do domínio `barberflow.invalid` (o de quem foi excluído).

**O que o e-mail traz:**

- o assunto `Lembrete do seu horário na BarberFlow`, com o nome da barbearia (`BARBERSHOP_NAME`; vazio, vale o `APP_NAME`);
- a data, o horário, o serviço e o barbeiro;
- o endereço e o telefone da barbearia (`BARBERSHOP_ADDRESS` e `BARBERSHOP_PHONE`), só quando estão preenchidos;
- como cancelar: quem tem conta cancela em Meus horários (o botão do e-mail leva ao endereço do `APP_URL`), até `CLIENT_CANCEL_MIN_HOURS` horas antes; quem não tem conta fala com a barbearia, no mesmo prazo.

O corpo do e-mail não leva o nome, o e-mail, o telefone nem as observações do cliente, e o cabeçalho `X-Appointment-Id` leva só o id do agendamento, para achar a mensagem sem abrir o corpo. O que o `ReminderSender` grava no log da aplicação (o aviso de um envio que falhou) também leva só o id.

O `MAIL_MAILER=log` do `.env.example` é outra coisa e só serve no local: em vez de enviar, ele grava a mensagem inteira no fim do `storage/logs/laravel.log`, com o endereço do cliente (`To:`), a data, o horário, o serviço e o barbeiro, como faz com a confirmação de conta e o "esqueci a senha". Fora do local, use um `MAIL_MAILER` que envie de verdade, como o `smtp`, para o log não guardar dado pessoal.

### Envio automático (comando)

O comando `appointments:send-reminders` manda o lembrete dos agendamentos que começam depois de agora e até `REMINDER_HOURS_BEFORE` horas à frente (padrão 24, com o limite incluído) e que ainda não receberam (`reminder_sent_at` nulo). O agendador do Laravel (`routes/console.php`) roda o comando a cada 15 minutos, com `onOneServer()` (com várias instâncias, só uma roda; precisa de cache compartilhado) e `withoutOverlapping(10)` (uma rodada não começa antes de a anterior terminar, e a trava vence em 10 minutos).

```bash
php artisan appointments:send-reminders
```

A saída diz quantos lembretes saíram, quantos foram pulados e quantos falharam (ex.: `Enviados: 1. Pulados: 0. Com falha: 0.`):

- **pulados:** o de cliente excluído, o de e-mail do domínio `barberflow.invalid` e o que outro envio pegou antes;
- **com falha:** o e-mail não saiu. O `reminder_sent_at` volta a nulo, e a rodada seguinte tenta de novo. Com alguma falha, o comando termina com o código 1, para o monitoramento perceber.

Cada lembrete sai uma vez. Antes de enviar, o comando reserva a linha com um `UPDATE ... WHERE reminder_sent_at IS NULL`, que também confere o status, a data, o horário, o barbeiro e o serviço que ele leu: duas rodadas ao mesmo tempo, ou o comando e o "Enviar lembrete agora", não mandam o mesmo lembrete duas vezes, e o e-mail fala do horário que estava na linha. Remarcar a data ou o horário volta o `reminder_sent_at` a nulo, e o lembrete do horário novo sai na rodada dele.

O "agora" é o `now()` do PHP, no fuso do `APP_TIMEZONE`: o banco filtra só pela data (`appointments.date`), e a conta exata do horário é feita em PHP, igual no SQLite e no MySQL.

O agendador precisa estar rodando: no local, `php artisan schedule:work` num terminal à parte; num servidor, o cron chama `php artisan schedule:run` a cada minuto (veja o README). Sem ele, o lembrete automático não sai, e o "Enviar lembrete agora" continua funcionando.

### Enviar lembrete agora

`POST /appointments/{id}/reminder`, sem corpo: manda o lembrete na hora, grava o `reminder_sent_at` e devolve o agendamento, no mesmo formato do `GET /appointments/{id}`. É o botão "Enviar lembrete agora" do detalhe da Agenda.

| Perfil | Pode |
|---|---|
| Admin | qualquer agendamento |
| Barbeiro | só os da própria agenda (o de outro barbeiro dá `403`) |
| Cliente | não, nem do próprio horário (`403`) |

- Vale a qualquer momento antes do início, mesmo fora da janela do comando, e conta como o lembrete do agendamento: depois dele, o comando não manda outro, a não ser que a data ou o horário sejam remarcados.
- Um envio a cada 10 minutos por agendamento (`resend_minutes`, fixo em `config/barberflow.php`), contando o lembrete do comando. Antes disso, `429` com o `Retry-After` em segundos. O limite é por agendamento: o de outro agendamento sai na hora.
- Remarcar não libera outro envio manual antes dos 10 minutos: a remarcação volta o `reminder_sent_at` a nulo (para o comando mandar o lembrete do horário novo na rodada dele), mas o último envio manual também fica guardado no cache, e é ele que vale.
- Cada usuário manda até 10 lembretes por minuto, somando todos os agendamentos (limitador `reminders`). Depois disso, `429` com `Retry-After`, como nos outros [limites de requisição](#limites-de-requisição).
- A linha do agendamento fica travada enquanto as regras são conferidas: dois cliques ao mesmo tempo passam um de cada vez, e o segundo já recebe o `429`. O e-mail sai depois de soltar a trava.
- Se o e-mail não sair, a resposta é `503`, e o `reminder_sent_at` volta ao que era (nulo, ou a hora do envio anterior).

| Situação | Resposta |
|---|---|
| Status que não recebe lembrete (em atendimento, concluído, cancelado ou faltou) | `422` em `reminder`: `Só é possível enviar o lembrete de um agendamento com status Agendado ou Confirmado.` |
| O agendamento já começou | `422` em `reminder`: `Este agendamento já começou: não há lembrete para enviar.` |
| Cliente excluído | `422` em `reminder`: `O cliente foi excluído: não há para quem enviar o lembrete.` |
| E-mail do domínio `barberflow.invalid` | `422` em `reminder`: `O cadastro do cliente não tem um e-mail que receba mensagens.` |
| Outro lembrete do mesmo agendamento há menos de 10 minutos, mesmo com uma remarcação no meio | `429` com `Retry-After`: `Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 6 minutos.` |
| Mais de 10 envios no mesmo minuto pelo mesmo usuário | `429` com `Retry-After`: `Muitas requisições. Tente de novo em 60 segundos.` |
| O e-mail não saiu | `503`: `Não foi possível enviar o lembrete agora. Tente de novo em alguns minutos.` |

### Exemplos

**Enviar agora** (como admin):

```bash
curl -X POST http://localhost:8000/api/appointments/15/reminder \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Resposta `200`, com o `reminder_sent_at` da hora do envio:

```json
{
  "id": 15,
  "client_id": 2,
  "barber_id": 1,
  "service_id": 1,
  "date": "2026-11-17",
  "start_time": "10:00:00",
  "end_time": "10:30:00",
  "status": "scheduled",
  "price": "35.00",
  "notes": null,
  "created_at": "2026-11-16T11:00:00.000000Z",
  "updated_at": "2026-11-16T11:00:00.000000Z",
  "reminder_sent_at": "2026-11-16T11:00:00.000000Z",
  "status_label": "Agendado",
  "client": { "id": 2, "name": "Bruno Costa" },
  "barber": { "id": 1, "name": "João Barbeiro" },
  "service": { "id": 1, "name": "Corte de cabelo" },
  "payment": null
}
```

O envio não muda o `updated_at`: ele é do agendamento, e o lembrete tem a própria hora.

**De novo, 4 minutos depois** (`429`, com o cabeçalho `Retry-After: 360`):

```json
{ "message": "Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 6 minutos." }
```

**De um atendimento concluído** (`422`):

```json
{
  "message": "Só é possível enviar o lembrete de um agendamento com status Agendado ou Confirmado.",
  "errors": {
    "reminder": ["Só é possível enviar o lembrete de um agendamento com status Agendado ou Confirmado."]
  }
}
```

---

## Payments (pagamentos)

Pagamento de um atendimento concluído: no máximo um por agendamento. As regras ficam em `App\Services\PaymentRecorder`, os status em `App\Enums\PaymentStatus` e as formas em `App\Enums\PaymentMethod`.

**Quem pode** (`App\Policies\PaymentPolicy`):

| Ação | Admin | Barbeiro | Cliente |
|---|---|---|---|
| Listar e ver | todos | só os dos agendamentos da agenda dele | só os próprios (inclusive as observações) |
| Registrar (`POST`), com ou sem o prêmio da fidelidade | de qualquer atendimento concluído | só dos atendimentos da agenda dele | não |
| Corrigir valor, forma e observações (`PUT`/`PATCH`) | sim, em qualquer status | não | não |
| Mudar o status | marcar como pago e estornar | só marcar como pago um pendente da agenda dele | não |

Registrar o pagamento de um agendamento de outro barbeiro dá `403`. Conta de barbeiro ou de cliente sem cadastro ligado também recebe `403`. Na listagem, os filtros não abrem os pagamentos dos outros: `barber_id` de outro barbeiro devolve a lista vazia.

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `appointment_id` | integer | sim | agendamento que existe e está com o status `completed` (concluído) e ainda sem pagamento |
| `amount` | decimal | não | de 0 a 999999.99, com no máximo 2 casas. Sem ele (ou `null`), vale o `price` copiado no agendamento. Com o prêmio da fidelidade, não mande: o valor sai do desconto (mandado, mesmo 0, dá `422`). Na resposta, texto com 2 casas: `"35.00"` |
| `method` | string | sim | `cash` (Dinheiro), `pix` (Pix), `credit_card` (Cartão de crédito) ou `debit_card` (Cartão de débito) |
| `status` | string | não | `pending` (Pendente) ou `paid` (Pago). Sem ele, o pagamento nasce `paid`. Ninguém registra um pagamento já estornado (`refunded` dá `422`) |
| `notes` | string | não | até 1000 caracteres. O cliente também vê. Vazio no pagamento de cliente excluído (veja as regras) |
| `use_loyalty_reward` | boolean | não | `true` paga com um prêmio da fidelidade do cliente: o desconto vem do `LOYALTY_DISCOUNT_PERCENT`, e o `amount` é calculado. Sem prêmio disponível, `422`. Veja [Fidelidade](#fidelidade) |

O servidor preenche o resto, e ignora esses campos quando vêm no corpo:

| Campo da resposta | O que é |
|---|---|
| `paid_at` | quando o pagamento virou pago, em UTC (ISO 8601). Vem do relógio do servidor; `null` enquanto está pendente. O estorno mantém a data em que foi pago |
| `discount` | o desconto do prêmio da fidelidade, em texto com 2 casas. Sem o prêmio, `"0.00"` |
| `loyalty_reward` | `true` quando o pagamento usou um prêmio da fidelidade |
| `method_label` e `status_label` | a forma e o status em português |
| `appointment` | o agendamento resumido: `id`, `date`, `start_time`, `end_time` e `client`, `barber` e `service` só com `id` e `name`. Sem as observações e sem o preço do agendamento |

### Regras

- Só atendimento concluído recebe pagamento. Agendado, confirmado, em atendimento, cancelado e faltou dão `422` em `appointment_id`: `Só é possível registrar o pagamento de um atendimento concluído.`
- Um pagamento por atendimento, para sempre: pendente, pago ou estornado, ele é o único. Um segundo `POST` para o mesmo agendamento, mesmo depois do estorno, dá `422` em `appointment_id`: `Este atendimento já tem pagamento registrado.`
- Dois registros para o mesmo atendimento ao mesmo tempo passam um de cada vez: o registro trava a linha do cliente e depois a do agendamento (a mesma ordem da agenda) antes de conferir o status, se já existe pagamento e os prêmios da fidelidade, e o segundo já enxerga o primeiro. Dois pagamentos do mesmo cliente com o prêmio também passam um de cada vez, e o segundo já conta o prêmio que o primeiro usou. O índice `unique` de `appointment_id` no banco é a última barreira: se mesmo assim um segundo pagamento chegar a ser gravado, a resposta é o mesmo `422`, nunca um erro 500.
- Com `use_loyalty_reward: true`, o pagamento usa um prêmio da fidelidade: o desconto é o `LOYALTY_DISCOUNT_PERCENT` do `price` do agendamento, e o `amount` é o que sobra. Sem prêmio disponível, `422` em `use_loyalty_reward`; com o `amount` junto, `422` em `amount` (veja [Fidelidade](#fidelidade)).
- Se o barbeiro do agendamento mudou entre a conferência da permissão e a trava, a resposta é `409`: `O agendamento mudou enquanto você registrava o pagamento. Recarregue e tente de novo.`
- Não existe `DELETE /payments/{id}` (`405`): estornar é mudar o status, e o pagamento fica no histórico. O estorno de um pagamento com o prêmio devolve o prêmio ao cliente.
- A correção pelo admin (`PUT`/`PATCH /payments/{id}`) muda só `amount`, `method` e `notes`, com as mesmas regras do `POST` (o `amount` mandado não pode ser vazio). O agendamento, o status, o `paid_at`, o `discount` e o `loyalty_reward` não mudam por ela.
- Excluir o cliente apaga as observações (`notes`) dos pagamentos dos agendamentos dele, junto com as dos agendamentos (LGPD). O pagamento continua no histórico, e o atendimento concluído dele ainda pode ser pago, mas sem observações: no registro e na correção, `notes` preenchido dá `422` em `notes` (`O cliente foi excluído: deixe as observações em branco.`), porque um texto novo voltaria a ligar dado pessoal ao cadastro. A conferência vem depois da trava, então um registro ou uma correção que esperou a exclusão terminar também é recusado.

### Status e transições

| Status | Nome | Pode ir para | Quem |
|---|---|---|---|
| `pending` | Pendente | `paid` | admin, ou o barbeiro do agendamento. O `paid_at` recebe a hora da mudança |
| `paid` | Pago | `refunded` | só o admin (estorno) |
| `refunded` | Estornado | (final) | |

Transição fora da tabela dá `422` em `status` (ex.: `Não é possível mudar de Estornado para Pago.`).

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/payments` | Lista do atendimento mais recente para o mais antigo (filtros abaixo), com `totals` | `200` + página |
| POST | `/payments` | Registra o pagamento de um atendimento concluído | `201` + pagamento |
| GET | `/payments/{id}` | Mostra um pagamento | `200` + pagamento |
| PUT/PATCH | `/payments/{id}` | Corrige `amount`, `method` e `notes` (só o admin) | `200` + pagamento |
| PATCH | `/payments/{id}/status` | Muda o status (`{"status": "refunded"}`) | `200` + pagamento |

### Filtros da listagem

| Parâmetro | Regras |
|---|---|
| `from` e `to` | `Y-m-d`: pela data do atendimento (não a do pagamento), com os dois dias incluídos. `to` não pode ser antes de `from` |
| `method` | uma das 4 formas |
| `status` | `pending`, `paid` ou `refunded` |
| `barber_id` e `client_id` | inteiro: pelo barbeiro e pelo cliente do agendamento |
| `page` e `per_page` | como nas outras listagens (`per_page` de 1 a 100, padrão 15) |

A ordem é a data e o horário do atendimento, do mais recente para o mais antigo, e depois o `id` do pagamento (o maior primeiro). Não tem `search`. Valor fora das regras dá `422` (ex.: `method=cheque`, `to` antes de `from`).

Além de `data`, `links` e `meta`, a resposta traz `totals`: para cada status, a quantidade de pagamentos (`payments`) e a soma dos valores (`total`, texto com 2 casas), com os mesmos filtros e somando todas as páginas. Assim a tela mostra o total do filtro sem ler a lista inteira. O filtro `status` vale também para os totais: com `status=paid`, os outros dois saem zerados.

### Exemplos

**Atendimentos concluídos sem pagamento** (o que a tela de Pagamentos lista no "Registrar pagamento"):

```bash
curl "http://localhost:8000/api/appointments?without_payment=1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Cada item de `data` é um agendamento concluído com `"payment": null`.

**Registrar** o pagamento no Pix, com o valor do agendamento:

```bash
curl -X POST http://localhost:8000/api/payments \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"appointment_id":9,"method":"pix","notes":"Pagou na saída"}'
```

Resposta `201`:

```json
{
  "discount": "0.00",
  "loyalty_reward": false,
  "appointment_id": 9,
  "amount": "25.00",
  "method": "pix",
  "status": "paid",
  "paid_at": "2026-11-16T11:00:00.000000Z",
  "notes": "Pagou na saída",
  "updated_at": "2026-11-16T11:00:00.000000Z",
  "created_at": "2026-11-16T11:00:00.000000Z",
  "id": 9,
  "method_label": "Pix",
  "status_label": "Pago",
  "appointment": {
    "id": 9,
    "date": "2026-11-13",
    "start_time": "09:00:00",
    "end_time": "09:30:00",
    "client": { "id": 1, "name": "Ana" },
    "barber": { "id": 2, "name": "João" },
    "service": { "id": 2, "name": "Barba" }
  }
}
```

Depois disso, o agendamento traz o pagamento resumido (`GET /appointments/9`):

```json
"payment": { "id": 9, "status": "paid", "status_label": "Pago" }
```

**Registrar com o prêmio da fidelidade**, sem o `amount` (o exemplo completo, com a consulta antes e depois, está em [Fidelidade](#fidelidade)):

```bash
curl -X POST http://localhost:8000/api/payments \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"appointment_id":13,"method":"cash","use_loyalty_reward":true}'
```

Resposta `201` com `"amount": "0.00"`, `"discount": "35.00"` e `"loyalty_reward": true` (com o desconto padrão de 100%).

**Atendimento que já tem pagamento**, ou não concluído (`422`):

```json
{
  "message": "Este atendimento já tem pagamento registrado.",
  "errors": {
    "appointment_id": ["Este atendimento já tem pagamento registrado."]
  }
}
```

```json
{
  "message": "Só é possível registrar o pagamento de um atendimento concluído.",
  "errors": {
    "appointment_id": ["Só é possível registrar o pagamento de um atendimento concluído."]
  }
}
```

**Listar com filtro** (os do Pix na semana de 09 a 14/11, 2 por página):

```bash
curl "http://localhost:8000/api/payments?from=2026-11-09&to=2026-11-14&method=pix&per_page=2" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Resposta `200`: `data` com os 2 pagamentos desta página, no formato do exemplo acima (o de 13/11 primeiro, depois o de 10/11), `links` e `meta` como nas outras listagens (`"total": 3` e `"last_page": 2`) e, no fim, os totais do filtro, somando as 2 páginas:

```json
"totals": {
  "pending": { "payments": 0, "total": "0.00" },
  "paid": { "payments": 3, "total": "120.00" },
  "refunded": { "payments": 0, "total": "0.00" }
}
```

**Estornar** (só o admin):

```bash
curl -X PATCH http://localhost:8000/api/payments/9/status \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"status":"refunded"}'
```

Resposta `200` com o pagamento: `"status": "refunded"`, `"status_label": "Estornado"` e o mesmo `paid_at` de antes. Tentar voltar para pago dá `422`:

```json
{
  "message": "Não é possível mudar de Estornado para Pago.",
  "errors": {
    "status": ["Não é possível mudar de Estornado para Pago."]
  }
}
```

**Corrigir** o valor e as observações (só o admin):

```bash
curl -X PUT http://localhost:8000/api/payments/9 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"amount":20,"notes":"Desconto de R$ 5,00 combinado"}'
```

Resposta `200` com `"amount": "20.00"` e as observações novas; `status`, `paid_at` e o agendamento continuam os mesmos.

---

## Fidelidade

A cada `LOYALTY_VISITS` visitas (padrão 10), o cliente ganha um prêmio: `LOYALTY_DISCOUNT_PERCENT` % de desconto (padrão 100, o atendimento grátis) no pagamento de um atendimento. As regras ficam em `App\Services\LoyaltyService`, e os dois números em `config/barberflow.php`, lidos do `.env`.

**Regras:**

- visita é um atendimento concluído do cliente. Cancelado, faltou e o que ainda vai acontecer não contam;
- o atendimento pago com o prêmio não conta como visita. Na hora de pagar, o próprio atendimento que está sendo pago também fica de fora: ele ainda não ajuda a ganhar o prêmio que o pagaria (a cada 10 atendimentos, é o seguinte que sai com o desconto);
- prêmios ganhos: visitas ÷ `LOYALTY_VISITS`, sem as casas decimais. Disponíveis: ganhos menos usados, nunca negativo;
- usado é o pagamento com o prêmio que não foi estornado, pendente ou pago. O estorno devolve o prêmio, e o atendimento continua fora das visitas, para o cliente não ganhar a visita e o prêmio de volta ao mesmo tempo. O estorno de um pagamento comum mantém a visita, porque o atendimento aconteceu;
- o desconto é o `price` do agendamento × `LOYALTY_DISCOUNT_PERCENT` ÷ 100, arredondado no centavo, e o valor é o que sobra do preço (50% de 35,55 dá desconto 17,78 e valor 17,77). A conta é feita em centavos, sem o erro de arredondamento do número decimal.

As contas são contagens no banco, iguais no SQLite e no MySQL. A listagem de clientes faz as mesmas contas na própria consulta da página, sem uma consulta por cliente.

### Consultar a fidelidade

`GET /clients/{id}/loyalty`. O admin e o barbeiro veem a de qualquer cliente; o cliente, só a própria (a de outro dá `403`). Cliente excluído dá `404`.

| Parâmetro | Regras |
|---|---|
| `appointment_id` | opcional: um agendamento deste cliente, que fica fora das visitas. É a conta do pagamento dele com o prêmio, a mesma do `POST /payments`, que o modal de pagamento das telas usa para mostrar se o prêmio vale. O de outro cliente, o que não existe e o de uma agenda que o usuário não vê (o barbeiro, num agendamento de outro barbeiro) dão o mesmo `422` (`O agendamento informado não foi encontrado entre os deste cliente.`), para a resposta não contar quais agendamentos das outras agendas são deste cliente |

| Campo da resposta | O que é |
|---|---|
| `client_id` | o cliente |
| `visits` | as visitas (sem o `appointment_id`, quando ele vem) |
| `visits_per_reward` | o `LOYALTY_VISITS`, no mínimo 1 |
| `progress` | visitas desde o último prêmio ganho, de 0 a `visits_per_reward` menos 1 (ex.: 7 de 10) |
| `rewards_earned` | prêmios ganhos |
| `rewards_used` | prêmios usados |
| `available_rewards` | prêmios para usar |
| `discount_percent` | o `LOYALTY_DISCOUNT_PERCENT`, de 0 a 100 |

A listagem de clientes (`GET /clients`) traz o mesmo resumo, sem o `client_id`, em `loyalty`, em cada cliente da página.

### Usar o prêmio no pagamento

No `POST /payments`, mande `"use_loyalty_reward": true` e deixe o `amount` de fora. A resposta traz `"loyalty_reward": true`, o `discount` e o `amount` que sobrou. Quem pode é quem registra o pagamento: o admin e o barbeiro do agendamento.

| Situação | Resposta |
|---|---|
| Sem prêmio disponível | `422` em `use_loyalty_reward`: `O cliente ainda não tem prêmio de fidelidade disponível: 7 de 10 visitas, sem contar este atendimento.` |
| `amount` mandado junto, mesmo 0 | `422` em `amount`: `Com o prêmio de fidelidade, o valor é calculado pelo desconto: não envie o valor.` |

O registro trava a linha do cliente antes da do agendamento: dois pagamentos do mesmo cliente com o prêmio, ao mesmo tempo, passam um de cada vez, e o segundo já conta o prêmio que o primeiro usou (o `LoyaltyConcurrencyTest` confere no MySQL). A correção do admin (`PUT /payments/{id}`) não mexe no `discount` nem no `loyalty_reward`, e o estorno devolve o prêmio.

No [dashboard](#dashboard), o corte grátis (valor zero) não conta como pagamento: fica fora do `revenue.payments`, do ticket médio e das formas de pagamento, porque não entrou dinheiro, e o atendimento continua contando como concluído. O prêmio com desconto parcial (`LOYALTY_DISCOUNT_PERCENT` menor que 100) tem valor e conta como qualquer pagamento.

### Exemplos

A Ana tem 11 atendimentos concluídos: 10 pagos e o de segunda 16/11 às 09:00, ainda sem pagamento.

**Consultar:**

```bash
curl http://localhost:8000/api/clients/1/loyalty \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Resposta `200`:

```json
{
  "client_id": 1,
  "visits": 11,
  "visits_per_reward": 10,
  "progress": 1,
  "rewards_earned": 1,
  "rewards_used": 0,
  "available_rewards": 1,
  "discount_percent": 100
}
```

**A conta do pagamento do atendimento das 09:00** (`?appointment_id=13`): ele sai das visitas, e o prêmio das 10 anteriores continua valendo para pagá-lo.

```json
{
  "client_id": 1,
  "visits": 10,
  "visits_per_reward": 10,
  "progress": 0,
  "rewards_earned": 1,
  "rewards_used": 0,
  "available_rewards": 1,
  "discount_percent": 100
}
```

**Pagar com o prêmio:**

```bash
curl -X POST http://localhost:8000/api/payments \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"appointment_id":13,"method":"cash","use_loyalty_reward":true}'
```

Resposta `201`:

```json
{
  "discount": "35.00",
  "loyalty_reward": true,
  "appointment_id": 13,
  "amount": "0.00",
  "method": "cash",
  "status": "paid",
  "paid_at": "2026-11-16T12:34:00.000000Z",
  "notes": null,
  "updated_at": "2026-11-16T12:34:00.000000Z",
  "created_at": "2026-11-16T12:34:00.000000Z",
  "id": 13,
  "method_label": "Dinheiro",
  "status_label": "Pago",
  "appointment": {
    "id": 13,
    "date": "2026-11-16",
    "start_time": "09:00:00",
    "end_time": "09:30:00",
    "client": { "id": 1, "name": "Ana Pereira" },
    "barber": { "id": 1, "name": "João Barbeiro" },
    "service": { "id": 1, "name": "Corte de cabelo" }
  }
}
```

Depois disso, o `GET /clients/1/loyalty` mostra `"visits": 10`, `"rewards_used": 1` e `"available_rewards": 0`: o atendimento pago com o prêmio não é visita.

**Sem prêmio disponível** (o Bruno, com 2 visitas antes do atendimento que está sendo pago), `422`:

```json
{
  "message": "O cliente ainda não tem prêmio de fidelidade disponível: 2 de 10 visitas, sem contar este atendimento.",
  "errors": {
    "use_loyalty_reward": ["O cliente ainda não tem prêmio de fidelidade disponível: 2 de 10 visitas, sem contar este atendimento."]
  }
}
```

**Na listagem de clientes** (`GET /clients`), cada item traz o resumo em `loyalty`:

```json
{
  "id": 2,
  "name": "Bruno Costa",
  "email": "bruno@example.com",
  "phone": null,
  "created_at": "2026-11-16T11:00:00.000000Z",
  "updated_at": "2026-11-16T11:00:00.000000Z",
  "deleted_at": null,
  "loyalty": {
    "visits": 3,
    "visits_per_reward": 10,
    "progress": 3,
    "rewards_earned": 0,
    "rewards_used": 0,
    "available_rewards": 0,
    "discount_percent": 100
  },
  "has_login": false
}
```

---

## Dashboard

`GET /dashboard?from=Y-m-d&to=Y-m-d` devolve os números da barbearia num período, só para o admin (Gate `view-dashboard`; barbeiro e cliente recebem `403`). As definições ficam em `App\Services\DashboardMetrics`, e as consultas são agregadas no banco (contagens e somas com `group by`), iguais no SQLite e no MySQL.

| Parâmetro | Regras |
|---|---|
| `from` e `to` | `Y-m-d`. Os dois juntos ou nenhum (um sem o outro dá `422`). `to` não pode ser antes de `from`, e o período tem no máximo 366 dias, contando os dois (`422` em `to`: `O período pode ter no máximo 366 dias.`) |

Sem `from` e `to`, vale o mês atual, do primeiro ao último dia, no fuso da aplicação (`APP_TIMEZONE`).

Tudo conta pela **data do atendimento** (`appointments.date`): um pagamento registrado dias depois entra no dia do atendimento. Dinheiro sai como texto com 2 casas (`"35.00"`). Taxas saem em %, com 1 casa decimal (`22.2`), e ficam `null` quando o divisor é zero.

| Campo | Fórmula |
|---|---|
| `period` | `from`, `to` e `days` (quantos dias o período tem) |
| `revenue.total` | soma dos pagamentos **pagos** dos atendimentos do período. Pendentes e estornados ficam de fora |
| `revenue.payments` | quantos pagamentos pagos entram na soma. O de valor zero (o corte grátis da fidelidade ou uma cortesia) fica de fora, porque não entrou dinheiro |
| `revenue.average_ticket` | ticket médio: `total ÷ payments` (`"0.00"` sem pagamento), sem os de valor zero |
| `revenue.by_method` | o mesmo faturamento por forma de pagamento, sempre com as 4 formas, mesmo sem valor. O de valor zero não aparece em forma nenhuma |
| `revenue.by_day` | o mesmo faturamento por dia, com todos os dias do período, mesmo sem valor |
| `appointments.total` | agendamentos do período, em qualquer status |
| `appointments.by_status` | quantos agendamentos em cada um dos 6 status, mesmo sem nenhum |
| `appointments.no_show_rate` | taxa de faltas: `faltou ÷ (concluído + faltou) × 100`. Cancelado não conta como falta, nem quando a barbearia cancela em cima da hora |
| `occupancy.occupied_minutes` | soma da duração (do início ao término) dos agendamentos que ocupam o horário: agendado, confirmado, em atendimento e concluído (cancelado e faltou liberam o horário) |
| `occupancy.minutes_per_day` | minutos de expediente por dia: fechamento menos abertura (`BUSINESS_OPENING` e `BUSINESS_CLOSING`) |
| `occupancy.business_days` | dias do período com expediente (`BUSINESS_DAYS`), inclusive os que ainda não chegaram |
| `occupancy.barbers` | barbeiros que contam: os cadastrados até o último dia do período e não excluídos antes do primeiro. Quem foi cadastrado depois do período não conta, então um cadastro novo não muda os números de um período que já passou |
| `occupancy.available_minutes` | `minutes_per_day × business_days × barbers` |
| `occupancy.rate` | ocupação: `occupied_minutes ÷ available_minutes × 100` |
| `top_services` | os 5 serviços com mais atendimentos concluídos, com o faturamento deles (pagamentos pagos). Empate fica com o maior faturamento e depois com o menor `id`. Serviço excluído aparece pelo nome |
| `barbers` | por barbeiro: concluídos, faltas (`no_show`) e faturamento. Entram os barbeiros que contam na ocupação, mesmo sem atendimento, e quem teve agendamento no período, em ordem de faturamento, concluídos, nome e `id` |

### Exemplo

A semana de segunda 09/11 a sábado 14/11/2026, com 2 barbeiros (Pedro e João), 13 agendamentos em todos os status e 6 pagamentos (4 pagos, 1 estornado e 1 pendente). São os mesmos números que o `DashboardApiTest` confere.

```bash
curl "http://localhost:8000/api/dashboard?from=2026-11-09&to=2026-11-14" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

Resposta `200`:

```json
{
  "period": { "from": "2026-11-09", "to": "2026-11-14", "days": 6 },
  "revenue": {
    "total": "155.00",
    "payments": 4,
    "average_ticket": "38.75",
    "by_method": [
      { "method": "cash", "label": "Dinheiro", "payments": 1, "total": "35.00" },
      { "method": "pix", "label": "Pix", "payments": 2, "total": "95.00" },
      { "method": "credit_card", "label": "Cartão de crédito", "payments": 1, "total": "25.00" },
      { "method": "debit_card", "label": "Cartão de débito", "payments": 0, "total": "0.00" }
    ],
    "by_day": [
      { "date": "2026-11-09", "payments": 2, "total": "90.00" },
      { "date": "2026-11-10", "payments": 1, "total": "40.00" },
      { "date": "2026-11-11", "payments": 0, "total": "0.00" },
      { "date": "2026-11-12", "payments": 1, "total": "25.00" },
      { "date": "2026-11-13", "payments": 0, "total": "0.00" },
      { "date": "2026-11-14", "payments": 0, "total": "0.00" }
    ]
  },
  "appointments": {
    "total": 13,
    "by_status": [
      { "status": "scheduled", "label": "Agendado", "count": 1 },
      { "status": "confirmed", "label": "Confirmado", "count": 1 },
      { "status": "in_progress", "label": "Em atendimento", "count": 1 },
      { "status": "completed", "label": "Concluído", "count": 7 },
      { "status": "cancelled", "label": "Cancelado", "count": 1 },
      { "status": "no_show", "label": "Faltou", "count": 2 }
    ],
    "no_show_rate": 22.2
  },
  "occupancy": {
    "rate": 5.4,
    "occupied_minutes": 390,
    "available_minutes": 7200,
    "minutes_per_day": 600,
    "business_days": 6,
    "barbers": 2
  },
  "top_services": [
    { "id": 2, "name": "Barba", "completed": 3, "revenue": "25.00" },
    { "id": 1, "name": "Corte", "completed": 2, "revenue": "75.00" },
    { "id": 3, "name": "Corte e barba", "completed": 2, "revenue": "55.00" }
  ],
  "barbers": [
    { "id": 1, "name": "Pedro", "completed": 3, "no_show": 1, "revenue": "90.00" },
    { "id": 2, "name": "João", "completed": 4, "no_show": 1, "revenue": "65.00" }
  ]
}
```

Como sair cada número:

- **Faturamento:** 35 + 55 + 40 + 25 = 155,00 em 4 pagamentos pagos; o estornado (25) e o pendente (55) ficam de fora. Ticket médio: 155 ÷ 4 = 38,75.
- **Faltas:** 2 ÷ (7 concluídos + 2 faltas) = 22,2%.
- **Ocupação:** 390 minutos agendados (sem o cancelado e as faltas) ÷ (600 minutos × 6 dias × 2 barbeiros = 7200) = 5,4%.
- **Top serviços:** Barba tem mais concluídos (3); Corte (35 + 40 = 75,00) e Corte e barba (55,00, sem o pendente de 55) empatam em 2, e o faturamento desempata.
- **Por barbeiro:** Pedro soma 35 + 55 = 90,00 (o estorno de 25 fica de fora) e João, 40 + 25 = 65,00 (o pendente de 55 fica de fora). Pedro vem primeiro porque fatura mais, mesmo com menos concluídos, e 90 + 65 dá os 155,00 do faturamento.

Período longo demais (`?from=2026-01-01&to=2027-01-02`, 367 dias), `422`:

```json
{
  "message": "O período pode ter no máximo 366 dias.",
  "errors": {
    "to": ["O período pode ter no máximo 366 dias."]
  }
}
```

---

## Limitações

- **Agenda por barbeiro:** os horários livres olham só a agenda do barbeiro escolhido. O conflito do cliente com outro barbeiro aparece só ao agendar (`422` em `client_id`).
- **Concorrência conferida no MySQL:** as travas que impedem dois agendamentos no mesmo horário, dois pagamentos do mesmo atendimento e o mesmo prêmio de fidelidade usado duas vezes só funcionam de verdade no MySQL; no SQLite dos testes, elas não fazem nada. O `composer test:mysql` (veja o README) roda o `AppointmentConcurrencyTest`, o `PaymentConcurrencyTest` e o `LoyaltyConcurrencyTest`, que conferem as travas com duas conexões, em passos de ordem fixa. A reserva do lembrete é um `UPDATE` condicional: o `ReminderCommandTest` e o `ReminderApiTest` mudam a linha logo antes dele (outra rodada, o envio manual, o cancelamento ou a remarcação) e logo antes do `UPDATE` que desfaz a reserva depois de uma falha, nos dois bancos, sem um teste de duas conexões no MySQL. A prova com pedidos simultâneos de verdade fica para o teste de carga `concorrencia.js`.
- **O cadastro de conta diz se o e-mail já tem conta:** `POST /register` responde `Este e-mail já está cadastrado.`. O login e o "esqueci a senha" não revelam isso.
- **E-mail repetido em dois pedidos ao mesmo tempo:** dois cadastros com o mesmo e-mail no mesmo instante (`POST /clients`, `POST /barbers`, o `PUT` deles ou o `POST /register`) passam os dois pela validação, e o índice `unique` do banco recusa o segundo com `500`, e não com o `422` de e-mail em uso. Nada fica gravado em dobro.
- **Servidor de e-mail fora do ar:** o `POST /register` responde `500` com a conta já criada, e o `POST /email/verification-notification` também responde `500`; o cliente entra e pede outro link depois. O `POST /forgot-password` responde o mesmo de sempre. A falha vai para o log sem o endereço.
- **Sem desfazer exclusão:** a linha fica no banco, mas não há rota para restaurar um registro excluído. No cliente, os dados pessoais já foram apagados.
- **Página de até 100 itens:** quem precisa de tudo (como a tela Agendar) pede `per_page=100`; com mais de 100 cadastros, os outros ficam nas páginas seguintes. A Agenda e Meus horários leem as páginas seguintes, até 1000 agendamentos.
- **Um pagamento por atendimento, para sempre:** depois do estorno, o atendimento não recebe outro pagamento. Para cobrar de novo um valor estornado por engano, o admin corrige o pagamento estornado (valor e observações), mas ele continua fora do faturamento.
- **Dashboard pela data do atendimento:** o faturamento de um dia é o dos atendimentos daquele dia, mesmo que o pagamento tenha sido registrado depois. Não existe um relatório pela data do pagamento.
- **Ocupação sem data de contratação:** o barbeiro cadastrado no meio do período conta o período inteiro nos minutos disponíveis, e o excluído no meio do período também (os atendimentos dele entram nos minutos ocupados). Só fica de fora quem foi cadastrado depois do último dia do período ou excluído antes do primeiro.
- **Lembrete só por e-mail, na hora e sem fila:** o comando manda um e-mail de cada vez, e um servidor de e-mail lento atrasa a rodada (a trava do `withoutOverlapping` vence em 10 minutos). Não há lembrete por SMS ou WhatsApp, nem confirmação de presença pelo e-mail.
- **O lembrete automático depende do agendador:** sem o `schedule:work` (local) ou o cron com o `schedule:run` (servidor), ele não sai. O "Enviar lembrete agora" funciona sem o agendador.
- **Um prêmio de fidelidade vale para qualquer serviço:** o desconto é sobre o preço do atendimento pago, seja um corte ou um combo, e o prêmio não vence. Mudar o `LOYALTY_VISITS` muda a conta de todos os clientes, inclusive a dos prêmios que já foram ganhos (os disponíveis nunca ficam negativos).

## Coleção do Postman

Importe o arquivo [`BarberFlow.postman_collection.json`](BarberFlow.postman_collection.json) no Postman ou no Insomnia. Como usar:

1. Nas variáveis da coleção, confira `baseUrl` (padrão `http://localhost:8000/api`) e preencha `loginEmail` e `loginPassword`. No ambiente local, o usuário de teste é `test@example.com`, e a senha dele está em `database/seeders/DatabaseSeeder.php`.
2. Rode **Autenticação > Login**. O teste do request guarda o token na variável `token`.
3. A coleção manda `Authorization: Bearer {{token}}` em todos os requests (autenticação no nível da coleção). Os requests abertos (login, cadastro de conta, esqueci a senha, redefinição e health) vão sem token.
4. Os requests de criar guardam o `id` criado em `serviceId`, `clientId` e `barberId`, que os de mostrar, atualizar e excluir usam. Rode o "Criar" de cada pasta antes dos outros.
5. A pasta **Agendamentos** cria o próprio cliente, barbeiro e serviço (`appointmentClientId`, `appointmentBarberId` e `appointmentServiceId`), para não depender dos que as outras pastas excluem. Rode na ordem: os três "Preparar", **Horários livres** (escolhe o próximo dia de expediente e guarda o primeiro e o último horário livre), **Criar** (guarda `appointmentId`), e depois listar, mostrar, remarcar, mudar o status e a exclusão que dá `409`.
6. A pasta **Pagamentos** precisa de um atendimento **concluído** sem pagamento. Ela não consegue criar um: a API só aceita pagamento de atendimento concluído, um atendimento só é concluído depois do horário de início, e ninguém agenda no passado. Por isso o primeiro request (**Atendimento concluído sem pagamento**, com `without_payment=1`) acha um que já existe e guarda o id em `paymentAppointmentId`; se a lista vier vazia, o `pm.test` dele falha e diz para concluir um na Agenda antes. Depois, na ordem: **Registrar** (no Pix, guarda `paymentId`), as duas recusas (`422` do atendimento que já tem pagamento e do não concluído, este com o `appointmentId` da pasta Agendamentos), **Listar com filtro** (período do atendimento, Pix e pago, conferindo os `totals`), **Mostrar**, **Corrigir**, **Estornar** e o estorno repetido que dá `422`. Como cada atendimento tem um pagamento só, o atendimento usado fica com o pagamento estornado.
7. A pasta **Lembretes** roda depois de **Agendamentos** e **Pagamentos**: **Enviar lembrete agora** manda o lembrete do agendamento criado (`appointmentId`), **Enviar de novo (429)** confere o limite de um envio a cada 10 minutos e o `Retry-After`, e o último confere o `422` de um atendimento concluído (o `paymentAppointmentId`). No local, o e-mail vai para o `storage/logs/laravel.log`. Rodar a pasta de novo antes de 10 minutos dá `429` já no primeiro request: rode antes a pasta **Agendamentos**, que cria outro agendamento.
8. A pasta **Fidelidade** também roda depois de **Agendamentos** e **Pagamentos**: a fidelidade do cliente criado (`appointmentClientId`), o resumo em cada cliente da listagem, o `422` da conta com o agendamento de outro cliente, o `422` do prêmio com o valor junto e os campos `discount` e `loyalty_reward` do pagamento da pasta **Pagamentos**. Para pagar com o prêmio, mande `"use_loyalty_reward": true`, sem `amount`, no **Registrar (payments)** de um atendimento cujo cliente tenha prêmio disponível.
9. A pasta **Dashboard** (só o admin) pede o mês atual, um período (`dashboardFrom` e `dashboardTo`, padrão novembro de 2026) e um período de 367 dias, que dá `422`.

Cada request tem um `pm.test` que confere o status esperado. As listagens trazem `page` e `per_page` ligados e `search` e `status` de exemplo desligados: marque o parâmetro na aba **Params** para usar.

Algumas variáveis começam vazias de propósito, para nenhum request trocar senha sem você pedir:

- `barberPassword`: com ela preenchida, o **Criar (barbers)** dá login ao barbeiro; vazia, ele fica sem login;
- `clientPassword`: senha da conta criada em **Conta de cliente > Criar conta de cliente**;
- `currentPassword`, `newPassword`, `resetToken` e `resetEmail`: usadas por **Trocar a senha** e **Redefinir a senha**. Sem preencher, esses requests só devolvem `422` e nada muda.

O **Logout** fica na pasta **Sair**, no fim da coleção, porque apaga o token.
