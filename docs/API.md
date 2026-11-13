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
| `405 Method Not Allowed` | Método errado | A rota existe, mas não com esse método (ex.: `DELETE /api/services`) |
| `419` | Sessão expirada | Só nas telas: o token CSRF da sessão do navegador não vale mais |
| `422 Unprocessable Entity` | Falha de validação | Campo ausente, inválido, duplicado ou fora do limite, filtro de listagem inválido, e-mail ou senha errados no login |
| `429 Too Many Requests` | Limite de requisições | Passou do limite por minuto (veja [Limites de requisição](#limites-de-requisição)) |
| `503 Service Unavailable` | Fora do ar | `/health` quando o banco ou o cache não responde |

### Sem login (401)

Sem o cabeçalho `Authorization`, ou com um token vencido, revogado (logout) ou inválido:

```json
{ "message": "Não autenticado." }
```

### Sem permissão (403)

O perfil de quem chamou não pode fazer a ação (ex.: barbeiro excluindo cliente, cliente listando clientes):

```json
{ "message": "Você não tem permissão para esta ação." }
```

Conta de cliente que ainda não confirmou o e-mail, em `/services`, `/clients` ou `/barbers` (todas as rotas com login exigem a confirmação, menos as da própria conta: `/user`, `/user/password`, `/logout` e `/email/verification-notification`):

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

A resposta traz o cabeçalho `Retry-After` (segundos até liberar) e os `X-RateLimit-Limit` e `X-RateLimit-Remaining`. O login errado várias vezes tem uma resposta própria, descrita em [Login](#login-post-login).

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

`GET /services`, `GET /clients` e `GET /barbers` devolvem uma página por vez, em ordem de nome (e de `id`, quando dois nomes são iguais). Parâmetros de query, todos opcionais:

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

- **Cliente:** os dados pessoais são apagados na exclusão (LGPD). O nome vira `Cliente removido`, o e-mail vira `removido+{id}@barberflow.invalid` e o telefone fica vazio.
- **Barbeiro:** só o nome continua, para o histórico mostrar quem atendeu. O e-mail é trocado por `removido+{id}@barberflow.invalid` e o telefone fica vazio.
- **Serviço:** só sai das listas; nada é alterado.

Como o e-mail do cliente ou do barbeiro excluído é trocado, o e-mail original fica livre: dá para cadastrar outra pessoa com ele. O domínio `.invalid` é reservado e nunca recebe mensagem. Por isso o cadastro e a atualização recusam e-mails `@barberflow.invalid` (`422`): se alguém cadastrasse antes o endereço que um excluído vai receber, a exclusão bateria no `unique` do banco.

A validação sozinha não basta no MySQL, que compara texto sem diferenciar acento: um e-mail como `removido+1@barberflow.ínvalid` passa na validação e, para o `unique` do banco, ocupa o endereço reservado do cadastro 1. Para isso não travar a exclusão, ela pergunta ao banco se o endereço já está em uso; se estiver, usa `removido+{id}.{12 letras e números aleatórios}@barberflow.invalid`.

### Datas e fuso horário

A aplicação roda no fuso `America/Sao_Paulo` (variável `APP_TIMEZONE`). `created_at`, `updated_at` e `deleted_at` saem no padrão ISO 8601 em UTC (o `Z` no fim): `2026-11-13T15:00:00.000000Z` é meio-dia em Brasília.

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

**Pedir o link (`POST /forgot-password`)**, aberto, com o campo `email`. A resposta é sempre a mesma, com e-mail cadastrado ou não, para ninguém usar esta rota para descobrir quem tem conta:

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
| Serviços | tudo | ver, inclusive os inativos | ver só os ativos (um inativo dá `404`, como se não existisse) |
| Barbeiros | tudo, inclusive criar e redefinir o login pelo campo `password` | ver, com e-mail, telefone e `has_login` | ver só `id` e `name`, para escolher com quem agendar. A busca olha só o nome |
| Clientes | tudo | ver, cadastrar e editar (não exclui) | ver e editar só o próprio cadastro (nome e telefone). Não lista os outros |
| Conta (`/user`, `/user/password`, `/logout`) | sim | sim | sim |

- Ação fora da tabela dá `403` (ex.: barbeiro em `POST /services`, cliente em `GET /clients`).
- Serviços, barbeiros e clientes exigem o e-mail confirmado. A conta de cliente que ainda não confirmou recebe `403` com `Confirme seu e-mail para continuar.` (admin e barbeiro já nascem com o e-mail confirmado).
- O cliente que manda `email` ao editar o próprio cadastro, mesmo vazio ou `null`, recebe `422` com `O campo e-mail não é permitido.`: o e-mail do cadastro fica com a barbearia, porque foi por ele que a conta foi ligada.
- Agendamentos, pagamentos, dashboard e fidelidade entram nas próximas etapas, com as regras por perfil descritas em [`ARQUITETURA.md`](ARQUITETURA.md#perfis-e-permissões).

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
| DELETE | `/services/{id}` | Exclui um serviço (exclusão lógica) | `204` |

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

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/clients` | Lista os clientes (filtros: `search`, `per_page`, `page`) | `200` + página |
| POST | `/clients` | Cadastra um cliente | `201` + cliente criado |
| GET | `/clients/{id}` | Mostra um cliente | `200` + cliente |
| PUT/PATCH | `/clients/{id}` | Atualiza um cliente | `200` + cliente atualizado |
| DELETE | `/clients/{id}` | Exclui o cliente e apaga os dados pessoais dele | `204` |

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

Depois disso, `carlos@example.com` pode ser cadastrado de novo, como um cliente novo. Se o cliente tinha conta, ela é apagada junto, com os tokens e as sessões abertas: ele não entra mais.

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
| DELETE | `/barbers/{id}` | Exclui o barbeiro (mantém o nome, troca o e-mail e apaga o telefone) | `204` |

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

## Limitações

- **Sem agendamentos ainda:** a agenda, os pagamentos e o dashboard entram nas próximas etapas. Hoje os únicos relacionamentos são os de login: `barbers.user_id` e `clients.user_id`.
- **O cadastro de conta diz se o e-mail já tem conta:** `POST /register` responde `Este e-mail já está cadastrado.`. O login e o "esqueci a senha" não revelam isso.
- **Sem desfazer exclusão:** a linha fica no banco, mas não há rota para restaurar um registro excluído. No cliente, os dados pessoais já foram apagados.
- **Página de até 100 itens:** quem precisa de tudo (como a tela Agendar) pede `per_page=100`; com mais de 100 cadastros, os outros ficam nas páginas seguintes.

## Coleção do Postman

Importe o arquivo [`BarberFlow.postman_collection.json`](BarberFlow.postman_collection.json) no Postman ou no Insomnia. Como usar:

1. Nas variáveis da coleção, confira `baseUrl` (padrão `http://localhost:8000/api`) e preencha `loginEmail` e `loginPassword`. No ambiente local, o usuário de teste é `test@example.com`, e a senha dele está em `database/seeders/DatabaseSeeder.php`.
2. Rode **Autenticação > Login**. O teste do request guarda o token na variável `token`.
3. A coleção manda `Authorization: Bearer {{token}}` em todos os requests (autenticação no nível da coleção). Os requests abertos (login, cadastro de conta, esqueci a senha, redefinição e health) vão sem token.
4. Os requests de criar guardam o `id` criado em `serviceId`, `clientId` e `barberId`, que os de mostrar, atualizar e excluir usam. Rode o "Criar" de cada pasta antes dos outros.

Cada request tem um `pm.test` que confere o status esperado. As listagens trazem `page` e `per_page` ligados e `search` e `status` de exemplo desligados: marque o parâmetro na aba **Params** para usar.

Algumas variáveis começam vazias de propósito, para nenhum request trocar senha sem você pedir:

- `barberPassword`: com ela preenchida, o **Criar (barbers)** dá login ao barbeiro; vazia, ele fica sem login;
- `clientPassword`: senha da conta criada em **Conta de cliente > Criar conta de cliente**;
- `currentPassword`, `newPassword`, `resetToken` e `resetEmail`: usadas por **Trocar a senha** e **Redefinir a senha**. Sem preencher, esses requests só devolvem `422` e nada muda.

O **Logout** fica na pasta **Sair**, no fim da coleção, porque apaga o token.
