# Referência da API — BarberFlow

API REST em JSON construída com Laravel. Todos os endpoints ficam sob o prefixo `/api`.

- **Base URL (local):** `http://localhost:8000/api`
- **Formato:** JSON (requisição e resposta)
- **Autenticação:** nenhuma por enquanto (veja [Limitações](#limitações))

## Convenções

Envie sempre os cabeçalhos:

```
Accept: application/json
Content-Type: application/json
```

### Códigos de resposta

| Código | Significado | Quando ocorre |
|---|---|---|
| `200 OK` | Sucesso | Listagem, consulta e atualização |
| `201 Created` | Recurso criado | `POST` bem-sucedido |
| `204 No Content` | Removido | `DELETE` bem-sucedido (sem corpo) |
| `404 Not Found` | Não encontrado | ID inexistente |
| `422 Unprocessable Entity` | Falha de validação | Campo ausente, inválido ou duplicado |

### Formato de erro de validação (422)

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

As mensagens de validação seguem o idioma configurado no Laravel (inglês por padrão).

### Formato de erro 404

```json
{
  "message": "No query results for model [App\\Models\\Service] 99."
}
```

(O texto exato da mensagem pode variar conforme a versão do Laravel e o modo de debug.)

### Atualização parcial

`PUT` e `PATCH` aceitam atualização parcial: envie apenas os campos que deseja alterar. Campos de `name`, `email`, `duration`, `price` e `status`, quando enviados, não podem ser vazios.

---

## Services (serviços)

Serviços oferecidos pela barbearia (corte, barba, combo etc.).

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `duration` | integer | sim | duração em **minutos**, mínimo 1 |
| `price` | decimal | sim | numérico, mínimo 0 (armazenado com 2 casas decimais) |
| `status` | string | sim | `active` ou `inactive` (padrão no banco: `active`) |

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/services` | Lista todos os serviços | `200` + array |
| POST | `/services` | Cria um serviço | `201` + serviço criado |
| GET | `/services/{id}` | Mostra um serviço | `200` + serviço |
| PUT/PATCH | `/services/{id}` | Atualiza um serviço | `200` + serviço atualizado |
| DELETE | `/services/{id}` | Remove um serviço | `204` |

### Exemplos

**Criar serviço**

```bash
curl -X POST http://localhost:8000/api/services \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"Corte de cabelo","duration":30,"price":40.00,"status":"active"}'
```

Resposta `201`:

```json
{
  "name": "Corte de cabelo",
  "duration": 30,
  "price": 40.0,
  "status": "active",
  "updated_at": "2026-10-30T12:00:00.000000Z",
  "created_at": "2026-10-30T12:00:00.000000Z",
  "id": 1
}
```

**Listar serviços**

```bash
curl http://localhost:8000/api/services -H "Accept: application/json"
```

Resposta `200`:

```json
[
  {
    "id": 1,
    "name": "Corte de cabelo",
    "duration": 30,
    "price": "40.00",
    "status": "active",
    "created_at": "2026-10-30T12:00:00.000000Z",
    "updated_at": "2026-10-30T12:00:00.000000Z"
  }
]
```

> O MySQL devolve colunas `DECIMAL` como string, por isso `price` aparece como `"40.00"` nas consultas.

**Atualizar parcialmente**

```bash
curl -X PATCH http://localhost:8000/api/services/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"price":45.00}'
```

**Remover**

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
| `email` | string | sim | e-mail válido e **único** na tabela `clients` |
| `phone` | string | não | até 20 caracteres |

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/clients` | Lista todos os clientes | `200` + array |
| POST | `/clients` | Cadastra um cliente | `201` + cliente criado |
| GET | `/clients/{id}` | Mostra um cliente | `200` + cliente |
| PUT/PATCH | `/clients/{id}` | Atualiza um cliente | `200` + cliente atualizado |
| DELETE | `/clients/{id}` | Remove um cliente | `204` |

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
  "updated_at": "2026-10-30T12:00:00.000000Z",
  "created_at": "2026-10-30T12:00:00.000000Z",
  "id": 1
}
```

**E-mail duplicado** — `422`:

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

Na atualização, o e-mail do próprio registro é ignorado na checagem de unicidade, então enviar o mesmo e-mail em um `PUT` não gera erro.

---

## Barbers (barbeiros)

### Campos

| Campo | Tipo | Obrigatório (POST) | Regras |
|---|---|---|---|
| `name` | string | sim | até 255 caracteres |
| `email` | string | sim | e-mail válido e **único** na tabela `barbers` |
| `phone` | string | não | até 20 caracteres |

### Endpoints

| Método | Rota | Descrição | Resposta |
|---|---|---|---|
| GET | `/barbers` | Lista todos os barbeiros | `200` + array |
| POST | `/barbers` | Cadastra um barbeiro | `201` + barbeiro criado |
| GET | `/barbers/{id}` | Mostra um barbeiro | `200` + barbeiro |
| PUT/PATCH | `/barbers/{id}` | Atualiza um barbeiro | `200` + barbeiro atualizado |
| DELETE | `/barbers/{id}` | Remove um barbeiro | `204` |

### Exemplo

```bash
curl -X POST http://localhost:8000/api/barbers \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"João Barbeiro","email":"joao@barberflow.com","phone":"67988880000"}'
```

---

## Limitações

- **Sem autenticação:** qualquer cliente HTTP pode criar, alterar e remover registros. O Laravel Sanctum já está instalado, mas as rotas de login/logout e o controle por perfil (admin, barbeiro, cliente) ainda serão implementados.
- **Sem paginação:** as listagens retornam todos os registros.
- **Sem relacionamentos:** ainda não há agendamentos ligando clientes, barbeiros e serviços.
- **Exclusão definitiva:** `DELETE` remove o registro do banco (não há exclusão lógica).

## Coleção do Postman

Importe o arquivo [`BarberFlow.postman_collection.json`](BarberFlow.postman_collection.json) no Postman ou no Insomnia. A coleção usa a variável `baseUrl` (padrão `http://localhost:8000/api`).
