# Testes de carga do BarberFlow

Testes com o [k6](https://grafana.com/docs/k6/latest/), rodados num container, contra a homolog local (as imagens de produção). Tudo aqui é para o computador de quem testa.

| Arquivo | Para que serve |
|---|---|
| `barberflow.js` | Carga da API com login: cenários smoke, carga (50 VUs) e estresse (200 VUs), com as metas p95 abaixo de 500 ms e menos de 1% de erro |
| `concorrencia.js` | Corrida da agenda: vários pedidos do mesmo horário, com o mesmo barbeiro e o mesmo cliente, ao mesmo tempo; só um pode passar |
| `resiliencia/` | Roteiro de queda e volta do banco, do app e do cache, com o script que mede o tempo (`resiliencia/README.md`) |
| `results/` | O JSON de cada rodada (`<RODADA>-<SCENARIO>.json`); o Git ignora os logs brutos |
| `sprint4/` | Os resultados da Sprint 4 (29/10/2026), só como referência (seção "Resultados da Sprint 4") |
| `../docker-compose.carga.yml` | Homolog de carga: os limites de requisição levantados e o serviço `k6` |

**Situação:** em 14/11/2026, os dois scripts passaram no `k6 inspect` (os três cenários e os valores inválidos) e rodaram contra uma API falsa com o mesmo contrato do `docs/API.md` (seção "Como os scripts foram conferidos"). A rodada de verdade, com as metas, precisa do login, do `/api/health` e da agenda, que chegam com as branches da aplicação: **verificada na F** (`feat/entrega-final`).

## Antes de começar

1. A homolog pronta: o `docker/homolog/homolog.env` gerado e o dev derrubado (`docs/nuvem/DOCKER.md`, "Homologação local").
2. A função `homologcarga`, que é a `homologmon` do `docs/nuvem/MONITORAMENTO.md` com mais o `docker-compose.carga.yml`. No PowerShell, na raiz do repositório:

   ```powershell
   function homologcarga { docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -f docker-compose.monitoring.yml -f docker-compose.carga.yml -p barberflow-homolog @args }
   ```

   No Git Bash:

   ```bash
   homologcarga() { MSYS_NO_PATHCONV=1 docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -f docker-compose.monitoring.yml -f docker-compose.carga.yml -p barberflow-homolog "$@"; }
   ```

   O `MSYS_NO_PATHCONV=1` impede o Git Bash de trocar caminhos de dentro do container, como o `/scripts/concorrencia.js` da seção "Concorrência da agenda", por caminhos do Windows.

3. A homolog de carga no ar. O `app` sobe de novo com os limites levantados, e o `web`, na rede do k6 (seção "Por que a homolog de carga"):

   ```powershell
   homologcarga up -d --build --wait
   ```

4. Um usuário de carga, com o perfil admin, criado pelo `AdminUserSeeder` (chega com a `feat/auth`), com a senha digitada sem aparecer na tela. Ele fica no banco da homolog: nas próximas rodadas, pule este passo e use o mesmo e-mail e a mesma senha no passo 5.

   ```powershell
   $env:ADMIN_NAME = 'Usuário de carga'
   $env:ADMIN_EMAIL = 'carga@example.com'
   $senha = Read-Host 'Senha do usuário de carga' -AsSecureString
   $env:ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $senha).Password
   homologcarga run --rm --no-deps -e ADMIN_NAME -e ADMIN_EMAIL -e ADMIN_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=AdminUserSeeder --force
   ```

   O `run --rm` sobe um container descartável que congela a configuração com o `ADMIN_*` e roda o seeder (o mesmo jeito do `docs/nuvem/TERRAFORM.md`, "Criar o administrador").

5. O e-mail e a senha do usuário de carga nas variáveis que o k6 lê. Elas valem só nessa janela do terminal e nunca vão para arquivo:

   ```powershell
   $env:LOAD_USER_EMAIL = $env:ADMIN_EMAIL
   $env:LOAD_USER_PASSWORD = $env:ADMIN_PASSWORD
   Remove-Item Env:ADMIN_NAME, Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
   ```

   No Git Bash, os passos 4 e 5 ficam assim:

   ```bash
   export ADMIN_NAME='Usuário de carga' ADMIN_EMAIL='carga@example.com'
   read -rsp 'Senha do usuário de carga: ' ADMIN_PASSWORD; echo; export ADMIN_PASSWORD
   homologcarga run --rm --no-deps -e ADMIN_NAME -e ADMIN_EMAIL -e ADMIN_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=AdminUserSeeder --force
   export LOAD_USER_EMAIL="$ADMIN_EMAIL" LOAD_USER_PASSWORD="$ADMIN_PASSWORD"
   unset ADMIN_NAME ADMIN_EMAIL ADMIN_PASSWORD
   ```

## Rodar

O `-e` sem valor repassa a variável do terminal para o container. Abra o Grafana (http://localhost:3000) durante o teste para ver o tráfego, o tempo de resposta e a CPU de cada container.

```powershell
homologcarga run --rm -e LOAD_USER_EMAIL -e LOAD_USER_PASSWORD -e SCENARIO=smoke -e RODADA=f1 k6
homologcarga run --rm -e LOAD_USER_EMAIL -e LOAD_USER_PASSWORD -e SCENARIO=carga -e RODADA=f1 k6
homologcarga run --rm -e LOAD_USER_EMAIL -e LOAD_USER_PASSWORD -e SCENARIO=estresse -e RODADA=f1 k6
```

Os mesmos comandos servem no Git Bash, com a função `homologcarga` dele.

| Cenário | Usuários (VUs) | Duração | O que cada VU faz, com 1 s de pausa entre as iterações |
|---|---|---|---|
| `smoke` | 1 | 30 s | Todas as rotas em toda iteração: serviços, barbeiros, agenda do dia, horários livres e o cadastro de um cliente |
| `carga` | sobe até 50 em 1 min, fica 3 min e desce em 30 s | 4 min 30 s | 30% serviços, 25% barbeiros, 20% agenda do dia, 15% horários livres e 10% cadastro de cliente |
| `estresse` | degraus de 50 por minuto até 200 e desce em 30 s | 4 min 30 s | Só leitura: 30% serviços, 25% barbeiros, 25% agenda do dia e 20% horários livres |

Antes de começar, o `setup()` confere o `/api/health`, faz o login de cada VU e acha um serviço e um barbeiro para os horários livres (numa homolog vazia, ele cria os dois, e o `teardown()` exclui). No fim, o `teardown()` faz o logout de todos os tokens. A carga deixa os clientes de teste que cadastrou (`Cliente k6 ...`, com e-mail `k6-...@example.com`).

### Metas e resultado

- **Metas:** p95 do tempo de resposta abaixo de 500 ms, menos de 1% de requisições com erro e mais de 99% das conferências certas, contando só as requisições do cenário (o login do `setup()` e a limpeza do `teardown()` ficam de fora).
- **Saída:** um resumo curto no terminal (requisições, tempo de resposta, erros e cada meta) e o JSON completo em `load-tests/results/<RODADA>-<SCENARIO>.json`. O JSON não leva os tokens (o `handleSummary` tira o `setup_data`) e não usa biblioteca baixada da internet.
- **Código de saída do k6:** 0 com todas as metas; 99 com alguma meta falhando; 107 quando o script parou (o motivo aparece na linha `level=error`, como "a aplicação recusou o host" ou "limite de requisições").

### Por que um token por VU

O Sanctum grava o `last_used_at` do token em toda requisição. Com um token só para todas as VUs, as 200 requisições por segundo do estresse disputariam a trava da mesma linha da tabela `personal_access_tokens`, e o teste mediria essa fila, e não a aplicação. Por isso o `setup()` faz um login para cada VU que o cenário chega a ter (1, 50 ou 200, em lotes de 10), e a VU N usa o token N (`exec.vu.idInTest`).

### Por que a homolog de carga

Os limites normais da aplicação (`config/barberflow.php`) barrariam o teste: todas as VUs entram com o mesmo usuário e do mesmo IP (o do container do k6). O `docker-compose.carga.yml` levanta só o que o teste precisa, só no computador local:

| Variável | Normal | Na carga | Por quê |
|---|---|---|---|
| `API_RATE_LIMIT` | 120 por minuto, por usuário | 100000 | 200 VUs fazem perto de 12 mil requisições por minuto, todas com o mesmo usuário |
| `LOGIN_IP_RATE_LIMIT` | 20 por minuto, por IP | 1000 | O `setup()` faz até 200 logins do mesmo IP |
| `LOGIN_RATE_LIMIT` | 5 senhas erradas por minuto, por e-mail e IP | 1000 | Com a senha certa não conta; alto para uma senha errada falhar no `setup()` sem bloquear o e-mail |
| `TRUSTED_HOSTS` | o host do `APP_URL` e os IPs privados | `web` | O k6 chama `http://web` pela rede do Docker, e a aplicação responderia 400 para esse host |

O k6 fica numa rede só dele e do `web` (`carga`): ele chega ao Nginx como um cliente de fora e não alcança o PHP-FPM do `app`, o banco nem o Redis. Por isso a `homologcarga up` recria também o `web`.

Para voltar aos limites normais, suba com a `homologmon` (`homologmon up -d --wait`), que recria o `app` e o `web` sem o `docker-compose.carga.yml`.

## Concorrência da agenda

```powershell
homologcarga run --rm -e LOAD_USER_EMAIL -e LOAD_USER_PASSWORD -e VUS=10 -e RODADA=f1 k6 run /scripts/concorrencia.js
```

1. O `setup()` faz um login por VU e cria um serviço, um barbeiro e um cliente só deste teste, com nomes únicos, e acha o primeiro horário livre do barbeiro a partir de amanhã.
2. As `VUS` (padrão 10, de 2 a 50) esperam o mesmo instante e mandam juntas o mesmo `POST /api/appointments`: mesmo cliente, mesmo barbeiro, mesmo serviço, mesmo dia e mesmo horário.
3. **Passa só se** exatamente um pedido receber 201 e todos os outros receberem 422 pelo horário ocupado (`start_time` ou `client_id`), sem nenhum 409, 500 ou erro de rede, e se a agenda terminar com um agendamento só naquele horário (conferido no `teardown()`).
4. O `teardown()` cancela o agendamento que passou, exclui os três cadastros do teste e faz o logout dos tokens. O resultado fica em `load-tests/results/<RODADA>-concorrencia.json`.

É a prova, com pedidos simultâneos de verdade, das travas do `App\Services\AppointmentScheduler` (o `AppointmentConcurrencyTest` do `composer test:mysql` prova com duas conexões).

## Como os scripts foram conferidos

Em 14/11/2026, nesta branch, que não tem o login nem a agenda:

| O quê | Resultado |
|---|---|
| `k6 inspect` nos três cenários e na concorrência com 2 e 50 VUs | Cenários e metas certos: 1, 50 e 200 VUs no máximo; `agendamentos_recusados` com `count==N-1` |
| `SCENARIO` e `VUS` inválidos | O k6 recusa, com a mensagem do script |
| Smoke contra a homolog atual | Parou no `setup()` com `/api/health: HTTP 404`, como esperado sem a `feat/api-qualidade`, e código 107 |
| Smoke, estresse e concorrência contra uma API falsa com o mesmo contrato (login, listagens, horários livres, agenda e logout) | Smoke e estresse com as metas; no estresse, 200 logins, os 200 tokens usados e todos com logout no fim; o JSON sem token e sem a senha |
| Concorrência contra a API falsa, com 20 VUs | Com a trava, 1 pedido criado e 19 recusados, e o teste passou; sem a trava (o defeito de propósito), 20 criados, e o teste falhou (código 99) |

A API falsa não fica no repositório: ela só imitou o contrato do `docs/API.md` para conferir a mecânica dos scripts.

## Resultados da Sprint 4

A pasta `sprint4/` guarda os resultados de 29/10/2026 (`sprint4/RESULTADOS.md`, o ambiente em `sprint4/AMBIENTE.md` e os resumos em `sprint4/results/`). **Eles servem de referência e não se comparam com os de agora:**

- a API era outra: sem login, sem paginação (o `GET /api/barbers` devolvia todos) e com 10 testes;
- a stack era outra: o código montado da pasta do Windows, o Laravel em modo de desenvolvimento na rodada "baseline" e o PHP-FPM com 5 processos (hoje são 10);
- o script era outro: sem login, com o resumo da biblioteca do k6 baixada da internet, no k6 1.3.0 (hoje, 2.3.0).

O achado que vale até hoje é o da rodada "otimizada": com a configuração de produção (cache de configuração e OPcache sem conferir a data dos arquivos), o p95 da carga caiu de 852 ms para 95 ms sem mudar código, e é essa configuração que a imagem `app` usa.

## Na AWS

O `docker-compose.carga.yml` é só local. Para medir a homolog da AWS, rode o k6 de fora da AWS contra o `APP_URL`, com os limites levantados por um tempo nos parâmetros do SSM do ambiente e um instance refresh (as instâncias leem o SSM só quando sobem), e volte os valores no fim:

```powershell
aws ssm put-parameter --name /barberflow/homolog/API_RATE_LIMIT --type String --value 100000 --overwrite
aws ssm put-parameter --name /barberflow/homolog/LOGIN_IP_RATE_LIMIT --type String --value 1000 --overwrite
docker run --rm -e LOAD_USER_EMAIL -e LOAD_USER_PASSWORD -e BASE_URL=<APP_URL> -e SCENARIO=carga -e RODADA=aws -e K6_NO_USAGE_REPORT=true --user 0 -v "${PWD}\load-tests:/scripts:ro" -v "${PWD}\load-tests\results:/results" grafana/k6:2.3.0 run /scripts/barberflow.js
aws ssm delete-parameter --name /barberflow/homolog/API_RATE_LIMIT
aws ssm delete-parameter --name /barberflow/homolog/LOGIN_IP_RATE_LIMIT
```

Faça o instance refresh (`docs/nuvem/TERRAFORM.md`, "Trocar a capacidade e as instâncias") depois de criar e depois de apagar os parâmetros. O teste passa pelo ALB e pela internet, e os números incluem a rede até a AWS. Nunca faça isso na prod com clientes: os limites protegem o login.

## Problemas comuns

- **`/api/health: HTTP 404`** no `setup()`: o código da homolog é anterior à `feat/api-qualidade`. Rode a partir da entrega final.
- **`HTTP 400. a aplicação recusou o host`**: a homolog subiu sem o `docker-compose.carga.yml` (falta o `TRUSTED_HOSTS=web`). Use a `homologcarga up -d --wait`.
- **`HTTP 429. limite de requisições`**: mesmo caso, sem os limites levantados.
- **`login: HTTP 422`**: o `LOAD_USER_EMAIL` ou o `LOAD_USER_PASSWORD` estão errados, ou não foram passados com `-e`.
- **`HTTP 403. o usuário de carga não tem permissão`**: o usuário não é admin. Crie com o `AdminUserSeeder` (passo 4).
- **`nenhum horário livre nos próximos 14 dias`** na concorrência: o expediente da homolog (`BUSINESS_*`) não tem horário nesses dias.
- **No Linux, os arquivos de `results/` ficam do root:** o k6 roda como root no container para conseguir gravar na pasta montada. Use `sudo chown -R "$USER" load-tests/results` depois.
