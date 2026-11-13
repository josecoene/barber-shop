# Referência da API do BarberFlow

API REST em JSON construída com Laravel. Todos os endpoints ficam sob o prefixo `/api`.

- **Base URL (local):** `http://localhost:8000/api`
- **Formato:** JSON (requisição e resposta)
- **Idioma:** mensagens em português do Brasil
- **Autenticação:** nenhuma por enquanto (veja [Limitações](#limitações))

## Convenções

Envie sempre os cabeçalhos:

```
Accept: application/json
Content-Type: application/json
```

Qualquer rota que começa com `/api` responde em JSON, mesmo sem o `Accept`.

O JSON pode trazer os acentos escapados (`"n\u00e3o"` no lugar de `"não"`). É o mesmo texto: qualquer leitor de JSON, como o Postman ou o `JSON.parse`, mostra a palavra normal.

### Códigos de resposta

| Código | Significado | Quando ocorre |
|---|---|---|
| `200 OK` | Sucesso | Listagem, consulta, atualização e `/health` com tudo no ar |
| `201 Created` | Recurso criado | `POST` bem-sucedido |
| `204 No Content` | Removido | `DELETE` bem-sucedido (sem corpo) |
| `404 Not Found` | Não encontrado | ID inexistente ou já excluído, ou rota que não existe |
| `405 Method Not Allowed` | Método errado | A rota existe, mas não com esse método (ex.: `DELETE /api/services`) |
| `422 Unprocessable Entity` | Falha de validação | Campo ausente, inválido, duplicado ou fora do limite, e filtro de listagem inválido |
| `503 Service Unavailable` | Fora do ar | `/health` quando o banco ou o cache não responde |

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

A rota fica fora do limite de requisições por minuto da API (`withoutMiddleware('throttle:api')` em `routes/api.php`): quando esse limite for ligado, o monitoramento e o deploy continuam consultando o `/health` sem receber `429`.

```bash
curl -i http://localhost:8000/api/health
```

---

## Services (serviços)

Serviços oferecidos pela barbearia (corte, barba, combo etc.).

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
curl "http://localhost:8000/api/services?status=active&search=corte" -H "Accept: application/json"
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
  -d '{"price":45}'
```

**Excluir**

```bash
curl -X DELETE http://localhost:8000/api/services/1 -H "Accept: application/json"
```

Resposta: `204 No Content`.

---

## Clients (clientes)

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `email` | string | sim | e-mail válido, até 255 caracteres e **único** na tabela `clients`. Não aceita o domínio `barberflow.invalid`, reservado para cadastros excluídos |
| `phone` | string | não | até 20 caracteres |

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
  "id": 1
}
```

**Buscar por nome ou e-mail**

```bash
curl "http://localhost:8000/api/clients?search=silva&per_page=10" -H "Accept: application/json"
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

Depois disso, `carlos@example.com` pode ser cadastrado de novo, como um cliente novo.

---

## Barbers (barbeiros)

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `email` | string | sim | e-mail válido, até 255 caracteres e **único** na tabela `barbers`. Não aceita o domínio `barberflow.invalid`, reservado para cadastros excluídos |
| `phone` | string | não | até 20 caracteres |

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/barbers` | Lista os barbeiros (filtros: `search`, `per_page`, `page`) | `200` + página |
| POST | `/barbers` | Cadastra um barbeiro | `201` + barbeiro criado |
| GET | `/barbers/{id}` | Mostra um barbeiro | `200` + barbeiro |
| PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro | `200` + barbeiro atualizado |
| DELETE | `/barbers/{id}` | Exclui o barbeiro (mantém o nome, troca o e-mail e apaga o telefone) | `204` |

### Exemplo

```bash
curl -X POST http://localhost:8000/api/barbers \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"João Barbeiro","email":"joao@barberflow.com","phone":"67988880000"}'
```

Na exclusão, o barbeiro sai das listas, o nome fica no banco, o telefone é apagado e o e-mail vira `removido+{id}@barberflow.invalid`, liberando `joao@barberflow.com` para outro cadastro.

---

## Limitações

- **Sem autenticação:** qualquer cliente HTTP pode criar, alterar e excluir registros. O Laravel Sanctum já está instalado, mas as rotas de login/logout e o controle por perfil (admin, barbeiro, cliente) ainda serão implementados.
- **Sem relacionamentos:** ainda não há agendamentos ligando clientes, barbeiros e serviços.
- **Sem desfazer exclusão:** a linha fica no banco, mas não há rota para restaurar um registro excluído. No cliente, os dados pessoais já foram apagados.
- **Página de até 100 itens:** quem precisa de tudo (como a tela Agendar) pede `per_page=100`; com mais de 100 cadastros, os outros ficam nas páginas seguintes.

## Coleção do Postman

Importe o arquivo [`BarberFlow.postman_collection.json`](BarberFlow.postman_collection.json) no Postman ou no Insomnia. A coleção usa a variável `baseUrl` (padrão `http://localhost:8000/api`). As listagens trazem `page` e `per_page` ligados e `search` e `status` de exemplo desligados: marque o parâmetro na aba **Params** para usar.
