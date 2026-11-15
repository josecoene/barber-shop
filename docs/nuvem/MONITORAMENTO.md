# Monitoramento do BarberFlow

Este guia explica a observabilidade do BarberFlow em dois lugares:

- **No computador, junto com a homolog:** Prometheus (métricas), Loki (logs, coletados pelo Alloy) e Grafana (o painel "BarberFlow: visão geral", com 10 painéis em 3 linhas). É o monitoramento da Sprint 4, que vivia num repositório local do Leonam (`barber-shop-cloud`) e agora está no repositório do grupo, com os nomes de serviço e de rede deste projeto.
- **Na AWS:** alarmes do CloudWatch com aviso por e-mail e um dashboard, no `terraform/monitoring.tf`. O que cada alarme olha, os limites e as variáveis estão no `docs/nuvem/TERRAFORM.md`, seção "Observabilidade".

**Situação:** o monitoramento local foi conferido em 14/11/2026 com a homolog no ar (seção "Situação conferida em 14/11/2026") e de novo em 15/11/2026, na entrega final, com o código inteiro da aplicação (seção "Situação conferida na entrega final"): os 4 alvos do Prometheus no ar, o painel no Grafana e os logs de todos os serviços chegando ao Loki. A parte da AWS está validada só no código (`terraform validate`, `terraform test` com o provider simulado e `trivy config`), como o resto do Terraform.

## Arquivos

| Arquivo | Para que serve |
|---|---|
| `docker-compose.monitoring.yml` | Prometheus, Grafana, Loki, Alloy, cAdvisor e os exporters do Nginx e do MySQL, sempre junto com a homolog |
| `monitoring/prometheus/prometheus.yml` | Os 4 alvos de métricas, coletados a cada 5 s |
| `monitoring/grafana/provisioning/` | Fontes de dados (Prometheus e Loki) e a pasta do painel, criadas na subida |
| `monitoring/grafana/dashboards/barberflow.json` | O painel "BarberFlow: visão geral" (10 painéis em 3 linhas) |
| `monitoring/loki/loki.yml` | Loki num processo só, com os logs em disco por 7 dias |
| `monitoring/alloy/config.alloy` | Coleta dos logs dos containers do projeto pelo socket do Docker, com a senha do root do MySQL trocada por `[oculto]` |
| `docker/mysql/init/01-exporter-user.sh` | Cria o usuário `exporter` do MySQL, só de leitura, junto com o banco da homolog |
| `docker/homolog/homolog.env.example` | Modelo do `homolog.env`, que guarda a chave e as senhas da homolog, inclusive as do monitoramento (o Git ignora o arquivo de verdade) |
| `docker-compose.carga.yml` | Homolog de carga, com os limites levantados e o k6 (`load-tests/README.md`) |

## O que sobe

| Serviço | Para que serve | Onde abre |
|---|---|---|
| `prometheus` | Guarda as métricas por 7 dias (volume `prometheusdata`) | http://localhost:9090, só neste computador |
| `grafana` | Mostra o painel e os logs; abre direto no painel, sem login, só para ver | http://localhost:3000, só neste computador |
| `loki` | Guarda os logs de todos os containers do projeto por 7 dias (volume `lokidata`) | Só a rede interna |
| `alloy` | Lê os logs dos containers pelo socket do Docker e manda ao Loki | Só a rede interna |
| `cadvisor` | Mede CPU e memória de cada container | Só a rede interna |
| `nginx-exporter` | Lê a página de métricas do Nginx (porta 8081 do `web`, que não é publicada) | Só a rede interna |
| `mysqld-exporter` | Lê conexões e consultas do MySQL, com o usuário `exporter` | Só a rede interna |

O monitoramento tem a própria rede (`monitoring`). Cada exporter entra também numa rede só dele e do serviço que lê: o `nginx-exporter` na `web-metrics`, com o `web`, e o `mysqld-exporter` na `db-metrics`, com o `db`. Assim nenhum dos dois entra na `frontend` nem na `backend` da homolog, onde estão o PHP-FPM do `app`, o Redis sem senha e o Mailpit. O Grafana e o Prometheus só aceitam conexões do 127.0.0.1.

Nada do monitoramento manda dado para fora. O Grafana sobe sem estatísticas de uso, sem procurar versão nova, sem notícias, sem a foto do Gravatar, sem o botão que publica o painel no `snapshots.raintank.io` e sem baixar plugins: sem o `GF_PLUGINS_PREINSTALL_DISABLED`, cada container novo instalaria do grafana.com, na versão mais nova do dia, os apps da lista de sugeridos e atualizaria os plugins que vêm na imagem, inclusive o Prometheus e o Loki do painel. O Loki e o Alloy (`--disable-reporting`) não mandam estatísticas de uso, e o k6 também não (`K6_NO_USAGE_REPORT`). A internet só entra no download das imagens.

## Subir

1. Tenha a homolog pronta para subir: o `docker/homolog/homolog.env` gerado e o dev derrubado (`docs/nuvem/DOCKER.md`, "Homologação local", passos 1 a 3). O `homolog.env` já traz as duas senhas do monitoramento: `HOMOLOG_DB_EXPORTER_PASSWORD` e `HOMOLOG_GRAFANA_ADMIN_PASSWORD`.
2. No PowerShell, na raiz do repositório, crie a função `homologmon`. Ela é a função `homolog` do `DOCKER.md` com mais um arquivo e vale só nessa janela do terminal:

   ```powershell
   function homologmon { docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -f docker-compose.monitoring.yml -p barberflow-homolog @args }
   ```

   No Git Bash:

   ```bash
   homologmon() { MSYS_NO_PATHCONV=1 docker compose --env-file docker/homolog/homolog.env -f docker-compose.prod.yml -f docker-compose.homolog.yml -f docker-compose.monitoring.yml -p barberflow-homolog "$@"; }
   ```

   O `MSYS_NO_PATHCONV=1` impede o Git Bash de trocar caminhos de dentro do container, como o `/docker-entrypoint-initdb.d/01-exporter-user.sh` de "Banco que já existe", por caminhos do Windows.

3. Suba tudo e espere ficar saudável:

   ```powershell
   homologmon up -d --build --wait
   ```

   Com a homolog já no ar, o `up` recria também o `web` e o `db`, que entram nas redes dos exporters, e os dois ficam alguns segundos fora.

Depois abra http://localhost:3000 (o painel) e http://localhost:9090/targets (os 4 alvos devem estar `UP`). Para editar o painel ou explorar os logs com mais liberdade, entre no Grafana com o usuário `admin` e a senha `HOMOLOG_GRAFANA_ADMIN_PASSWORD` do `homolog.env`.

**Derrube com a mesma função que subiu:** `homologmon down`, sem `-v`. Com a `homolog` (sem o monitoramento), o compose não enxerga os containers do monitoramento, avisa `Found orphan containers` e não consegue apagar as redes, que eles ainda usam. Se isso acontecer, rode `homologmon down`. O `-v` apagaria também o banco e o histórico de métricas e logs.

- Os volumes `prometheusdata` e `lokidata` guardam 7 dias de métricas e de logs entre uma subida e outra.
- O Grafana não tem volume: a cada subida nova ele nasce com a senha do `homolog.env` e com o painel e as fontes de dados dos arquivos de `monitoring/grafana/`. Mudança feita no painel pela tela se perde; para mudar o painel de vez, edite o `barberflow.json` e suba de novo.
- O monitoramento é da homolog, que roda as imagens de produção. O dev (`docker-compose.yml`) não tem monitoramento: o código montado da pasta do Windows deixa cada requisição bem mais lenta, e os números não diriam nada sobre a produção (a Sprint 4 mediu isso, `load-tests/sprint4/RESULTADOS.md`).

## O painel "BarberFlow: visão geral"

São 10 painéis, organizados em 3 linhas (Tráfego, Recursos e Logs centralizados), os mesmos da Sprint 4, agora lendo o serviço `web` (o Nginx) no lugar do `webserver` antigo. Para o Grafana, cada linha também é um item do painel, então a API conta 13 (10 painéis e 3 linhas):

| Painel | De onde vem | O que mostra |
|---|---|---|
| Tráfego (linha) | | |
| Requisições por segundo | Prometheus (`nginx-exporter`) | Requisições atendidas pelo Nginx |
| Tempo de resposta no servidor (p50 / p95) | Loki (log de acesso do `web`) | O `$request_time` de cada requisição. A meta dos testes de carga é p95 abaixo de 500 ms |
| Respostas por status HTTP | Loki (log de acesso do `web`) | Quantas respostas de cada código; o 502 e o 504 aparecem com o `app` fora do ar |
| Conexões no Nginx | Prometheus | Conexões ativas e esperando |
| Erros da aplicação (logs do Laravel) | Loki (log do `app`) | Linhas com `ERROR` |
| Alvos de métricas ativos | Prometheus | Quantos alvos ele lê; o esperado são 4 |
| Recursos (linha) | | |
| CPU por serviço | Prometheus (cAdvisor) | CPU de cada container (1 é um núcleo inteiro) |
| Memória por serviço | Prometheus (cAdvisor) | Memória de cada container |
| MySQL: conexões e consultas por segundo | Prometheus (`mysqld-exporter`) | Conexões abertas e consultas por segundo |
| Logs centralizados (linha) | | |
| Logs de todos os serviços | Loki | Os logs de todos os containers do projeto, com o filtro "Serviço (logs)" no topo |

O tempo de resposta e os status saem do log de acesso do Nginx, que manteve o começo de linha da Sprint 4 (`docs/nuvem/DOCKER.md`, seção "Nginx"). Mudou o formato do log, mude também a consulta desses dois painéis no `barberflow.json`.

## Os logs no Loki

- O Alloy lê o que cada container do projeto `barberflow-homolog` escreve no stdout e no stderr e manda ao Loki com dois rótulos: `service` (o serviço do compose, como `app`, `web` e `db`) e `container`.
- No Grafana, Explore, fonte Loki. Alguns filtros úteis:

  | O que procurar | Consulta |
  |---|---|
  | Erros da aplicação | `{service="app"} \|= "ERROR"` |
  | Respostas 5xx do Nginx | `{service="web"} \|~ "\" 5[0-9][0-9] "` |
  | Uma rota | `{service="web"} \|= "/api/services"` |
  | O agendador | `{service="scheduler"}` |
  | Lembrete por e-mail que falhou (a rodada do agendador) | `{service="scheduler"} \|~ "Lembrete\|ERROR"` |

- **A rodada do lembrete escreve no log do `scheduler`.** Desde a segunda rodada de acabamento (15/11/2026), ela roda dentro do processo do agendador, e não como um comando à parte com a saída no `/dev/null`: quando um lembrete falha, o `scheduler` escreve um aviso `Lembrete por e-mail não enviado.` só com o `appointment_id` e uma linha `ERROR` só com as contas da rodada, e o Alloy leva as duas ao Loki, como o resto da saída do container. Antes, essas linhas se perdiam. A linha da rodada (`Running [appointments:send-reminders] ... DONE`) sai `DONE` mesmo quando ela falha, por um detalhe do Laravel 13.33: procure o `ERROR`, e não o `FAIL`. Conferido em 15/11/2026 na saída do `scheduler` da homolog, sem o monitoramento (`load-tests/resiliencia/README.md`, "Com o limite de tempo do servidor de e-mail").
- O log de acesso do Nginx grava o caminho sem a query string e esconde o token dos links de redefinição de senha e de confirmação de e-mail. Em 14/11/2026, uma busca com um e-mail na query string (`/api/services?search=...`) chegou ao Loki só como `/api/services`, e o e-mail não aparece em nenhuma linha.
- O Grafana grava só avisos e erros (`GF_LOG_LEVEL=warn`). Sem isso, cada subida nova encheria o Loki com perto de 2 mil linhas da migração do banco interno dele.
- Quando cria o banco da homolog, o MySQL escreve no log, uma vez, a senha aleatória do root (`GENERATED ROOT PASSWORD: ...`). O Alloy troca a senha por `[oculto]` antes de mandar a linha ao Loki (`loki.process` do `config.alloy`), porque o Grafana mostra os logs sem login. No `docker compose logs db` ela continua aparecendo.
- O Mailpit não grava no log quem mandou nem quem recebeu cada e-mail (conferido com um e-mail de teste na versão 1.33.1).
- Os logs ficam 7 dias (`retention_period` do `loki.yml`).

## O usuário do exporter

O `mysqld-exporter` entra no MySQL com o usuário `exporter`, que o `docker/mysql/init/01-exporter-user.sh` cria:

- só com `PROCESS` e `REPLICATION CLIENT` (status do servidor) e `SELECT` no `performance_schema`. Ele não lê as tabelas da aplicação, que têm dados pessoais (a Sprint 4 dava `SELECT` em tudo);
- com no máximo 3 conexões, para nunca disputar o banco com o app;
- com a senha `HOMOLOG_DB_EXPORTER_PASSWORD` do `homolog.env`, que o `docker-compose.homolog.yml` passa ao banco e o `docker-compose.monitoring.yml` passa ao exporter.

O MySQL roda os scripts de `/docker-entrypoint-initdb.d` uma vez só, quando cria o banco. Por isso a pasta fica montada na homolog sempre, com ou sem o monitoramento: todo banco novo da homolog já nasce com o usuário, e o monitoramento pode entrar a qualquer hora.

### Banco que já existe

Um banco da homolog criado antes da `feat/observabilidade` não tem o usuário `exporter`, e o alvo `mysql` do Prometheus fica `UP` com `mysql_up` em 0 (o painel do MySQL fica vazio). A senha do root da homolog é aleatória e não fica guardada, então o usuário não se cria com um `mysql -u root`. O mesmo script resolve com o banco parado: um container avulso do serviço `db` sobe o MySQL por alguns segundos com `--init-file` (o jeito que a documentação do MySQL dá para mexer em usuários sem a senha do root), sem rede, cria o usuário e desliga.

No PowerShell, com a função `homologmon` e o `homolog.env` com a `HOMOLOG_DB_EXPORTER_PASSWORD`:

```powershell
homologmon stop db
homologmon run --rm --no-deps --entrypoint bash db /docker-entrypoint-initdb.d/01-exporter-user.sh
homologmon up -d --wait
```

No Git Bash, os mesmos três comandos com a função `homologmon` dele.

1. Para o banco. Dois MySQL no mesmo volume estragariam os dados; com o banco no ar, o script se recusa a seguir.
2. Roda o script num container avulso do serviço `db`, com o mesmo volume e a mesma senha do exporter. No fim aparece `usuário exporter pronto`. O arquivo com a senha só existe dentro desse container, que o `--rm` apaga.
3. Sobe tudo de novo. Em poucos segundos o `mysql_up` vira 1.

Rodar de novo não estraga nada: o script acerta a senha de um `exporter` que já existe (útil se a `HOMOLOG_DB_EXPORTER_PASSWORD` mudou). O caminho foi conferido em 14/11/2026 num MySQL 8.4.13 descartável com um banco criado sem o script e com a senha do root aleatória (seção "Situação conferida em 14/11/2026"); os três comandos acima juntam esse script ao compose da homolog e não rodaram no banco de verdade da homolog, que ficou intocado.

## Conferir pela API

Com a homolog e o monitoramento no ar, no PowerShell:

```powershell
(Invoke-RestMethod http://127.0.0.1:9090/api/v1/targets).data.activeTargets | Select-Object scrapeUrl, health
(Invoke-RestMethod "http://127.0.0.1:9090/api/v1/query?query=mysql_up").data.result.value
(Invoke-RestMethod http://127.0.0.1:3000/api/dashboards/uid/barberflow).dashboard.panels.Count
(Invoke-RestMethod http://127.0.0.1:3000/api/datasources/proxy/uid/loki/loki/api/v1/label/service/values).data
```

1. Os 4 alvos (`prometheus`, `cadvisor`, `nginx-exporter` e `mysqld-exporter`) com `health` `up`.
2. O `mysql_up` em 1: o exporter entrou no banco.
3. O painel com 13 itens: os 10 painéis e as 3 linhas.
4. Os serviços com log no Loki (pela fonte de dados do Grafana, sem login): `app`, `web`, `db`, `redis`, `scheduler` e os do monitoramento.

No Git Bash, o mesmo com `curl -s` e o endereço de cada linha.

## Na AWS

O Prometheus, o Loki e o Grafana são só da máquina local: na AWS, as métricas são as do CloudWatch (ALB, Auto Scaling, RDS e ElastiCache) e os logs vão para os log groups do `terraform/logs.tf` (`docs/nuvem/TERRAFORM.md`, "Logs no CloudWatch").

O `terraform/monitoring.tf` cria, em cada ambiente:

- um tópico SNS que recebe os alarmes e, com `alarm_email` preenchido, uma assinatura por e-mail (a AWS manda um e-mail de confirmação, e o aviso só chega depois do clique no link);
- 12 alarmes na homolog e 15 na prod (o cache da prod tem 2 nós): 5xx do ALB e das instâncias, p95 do tempo de resposta, instâncias sem saúde, instâncias em serviço abaixo do mínimo, CPU, espaço livre, memória livre e conexões do RDS, e CPU do Valkey, memória e evictions de cada nó do cache;
- o dashboard `barberflow-<ambiente>`, com o estado de todos os alarmes e os gráficos de cada um.

A tabela dos alarmes, os limites, as variáveis, o tratamento da falta de dado e o custo estão no `docs/nuvem/TERRAFORM.md`, seção "Observabilidade", e no `docs/nuvem/CUSTOS.md`.

## Versões fixas

Conferidas em 14/11/2026 no Docker Hub, no GHCR e nas releases do GitHub de cada projeto. A regra é a do `docs/nuvem/CI-CD.md` (o `cooldown` do Dependabot): a versão estável mais nova com pelo menos 7 dias de publicada. A exceção é versão nova que corrige falha de segurança da própria ferramenta, que não espera.

| Imagem | Versão | Por quê |
|---|---|---|
| Prometheus | `prom/prometheus:v3.16.0` | A mais nova, de 05/11/2026 |
| Grafana | `grafana/grafana:13.3.1` | De 10/11/2026, com 4 dias: corrige seis CVEs do próprio Grafana |
| Loki | `grafana/loki:3.7.9` | A 3.7.10 saiu em 13/11/2026, só com dependências atualizadas, e ainda não passou dos 7 dias |
| Alloy | `grafana/alloy:v1.21.3`, também pelo digest | A v1.22.0 saiu em 13/11/2026, com mudanças que quebram configurações de outros componentes |
| cAdvisor | `ghcr.io/google/cadvisor:0.60.6`, também pelo digest | A mais nova, de 18/09/2026 |
| Exporter do Nginx | `nginx/nginx-prometheus-exporter:1.5.3` | A mais nova, de 14/08/2026 |
| Exporter do MySQL | `prom/mysqld-exporter:v0.21.0` | A mais nova, de 22/10/2026 |
| k6 (testes de carga) | `grafana/k6:2.3.0` | A 2.4.0 saiu em 12/11/2026 e ainda não passou dos 7 dias; as correções de segurança dela ficam em partes do k6 que os scripts não usam (HTML e gRPC) |

- **Digest:** o Alloy (que lê o socket do Docker) e o cAdvisor (que roda `privileged`, com a raiz da máquina virtual do Docker montada, inclusive o disco do Windows) enxergam o computador inteiro. Por isso vão também pelo digest do índice multiplataforma, como as ferramentas do CI: uma tag reescrita no registro não chega a eles. O digest sai na linha `Digest` do `docker buildx imagetools inspect <imagem>:<tag>`; troque a tag e o digest juntos.
- **Dependabot:** o bloco `docker-compose` do `.github/dependabot.yml` propõe toda segunda as versões novas das imagens dos compose, com os mesmos 7 dias de espera, e troca o digest junto (`docs/nuvem/CI-CD.md`, "Dependabot").
- **À mão:** troque a tag no `docker-compose.monitoring.yml` (ou no `docker-compose.carga.yml`, o k6), suba de novo e confira pela API (seção acima). Leia as notas da versão nova antes: a Sprint 4 usava a Grafana 12 e o k6 1, e as duas mudaram de versão principal sem mudar nada nos arquivos daqui.

## Situação conferida em 14/11/2026

Na homolog (base `fix/menu-e-setup`, sem o login nem o `/api/health`), com o MySQL num tmpfs, porque o volume `barberflow-homolog_dbdata` deste computador foi criado com uma senha que se perdeu (`docs/nuvem/DOCKER.md`, "Problemas comuns"):

| O quê | Resultado |
|---|---|
| `docker compose config` da homolog com o monitoramento e com a carga | Válido; o Alloy recebe `COMPOSE_PROJECT=barberflow-homolog` e as portas novas ficam no 127.0.0.1 |
| `homologmon up -d --wait` | Os 13 containers saudáveis (ou no ar, os que não têm healthcheck) em 20 s |
| Alvos do Prometheus (API) | `prometheus`, `cadvisor`, `nginx` e `mysql` com `health` `up`; `mysql_up` e `nginx_up` em 1 |
| Grafana (API) | `database: ok`, versão 13.3.1; o painel `barberflow` com 13 itens (10 painéis e 3 linhas), provisionado na pasta BarberFlow; o admin entra com a senha do `homolog.env`, e `admin`/`admin` recebe 401 |
| Fontes de dados (API do Grafana) | Prometheus e Loki com `OK` |
| Loki (pela fonte de dados do Grafana) | Logs dos 13 serviços do projeto; as consultas dos painéis de tempo de resposta e de status responderam (p95 de 6 ms e contagem por status com 200 e 404) |
| E-mail na query string | Não chegou ao Loki |
| Usuário exporter, banco novo | Criado pelo entrypoint do MySQL, com o script executado e com o script carregado; entra, lê o status e o `performance_schema` e recebe `SELECT command denied` numa tabela da aplicação |
| Usuário exporter, banco que já existe | Num MySQL 8.4.13 descartável, criado sem o script e com a senha do root aleatória: com o banco parado, o script criou o usuário pelo `--init-file`, rodou duas vezes sem erro, não deixou o arquivo da senha para trás e, com o banco no ar, recusou seguir; a senha de teste tinha aspas simples e duplas, barra invertida, cifrão e exclamação |
| ShellCheck 0.11.0 no `01-exporter-user.sh` | Nenhum aviso |
| `homologmon down` (sem `-v`) | Tudo derrubado; os volumes ficaram |

### Depois da revisão

As correções da revisão (plugins do Grafana, estatísticas do Alloy, senha do root no Loki, digest e redes dos exporters e do k6) foram conferidas no mesmo dia, do mesmo jeito (MySQL num tmpfs e um `homolog.env` descartável, fora do repositório):

| O quê | Resultado |
|---|---|
| `docker compose config` com o monitoramento e com a carga | Válido. O `web` fica na `frontend` e na `web-metrics` (e na `carga`, na homolog de carga), o `db` na `backend` e na `db-metrics`, cada exporter na rede do serviço dele e na `monitoring`, e o k6 só na `carga`. Sem o monitoramento, o `web` e o `db` continuam só nas redes da homolog |
| `up -d --wait` | Os 13 containers saudáveis (ou no ar) em 20 s; os 4 alvos `up`, `mysql_up` e `nginx_up` em 1, e CPU e memória dos 13 containers no cAdvisor |
| Grafana | O painel com 13 itens (10 painéis e 3 linhas) e as duas fontes com `OK`. Nenhum plugin baixado: a pasta `/var/lib/grafana/plugins` vazia, o Prometheus e o Loki na versão 13.2.5 da imagem, e as quatro opções novas ligadas na API de configurações do admin |
| Grafana sem rede (`--network none`), antes e depois da correção | Antes, o log mostrou a tentativa de instalar do grafana.com 5 apps (`grafana-lokiexplore-app`, `grafana-pyroscope-app`, `grafana-exploretraces-app`, `grafana-metricsdrilldown-app` e `grafana-advisor-app`) e de procurar versão nova dos 15 plugins da imagem; depois, o instalador nem roda (`Skipping disabled service`) |
| Alloy | Roda com `--disable-reporting`, sem erro no log; o Alloy e o cAdvisor sobem pelo digest |
| Senha do root no Loki | A linha `GENERATED ROOT PASSWORD` do banco novo chegou ao Loki com `[oculto]`; no `docker compose logs db`, continua com a senha |
| Redes | O `mysqld-exporter` resolve só o `db` (não acha `app`, `redis`, `mailpit` nem `web`); do container do k6, o `/up` e o `/login` do `web` respondem, e `app`, `redis`, `mailpit` e `db` dão `bad address` |
| Funções do Git Bash | Com o `MSYS_NO_PATHCONV=1`, o `run ... db` acha o `/docker-entrypoint-initdb.d/01-exporter-user.sh` e o `exec -e HOME=/tmp` chega como `/tmp`. Sem ele, o caminho vira `C:/Program Files/Git/...` (`No such file or directory`) e o `HOME`, a pasta temporária do Windows |
| `down` (sem `-v`) | Tudo derrubado; os volumes ficaram, e o `barberflow-homolog_dbdata` não foi usado |

## Situação conferida na entrega final

Em 15/11/2026, na `feat/entrega-final`, com o código inteiro da aplicação (login, `/api/health`, agenda e pagamentos) e os dados do `DemoSeeder`. O MySQL ficou num tmpfs, pelo mesmo motivo da seção anterior:

| O quê | Resultado |
|---|---|
| `homologmon up -d --wait` | Os 13 containers saudáveis (ou no ar, os sem healthcheck) em 18,2 s |
| `/api/health` | `{"status":"ok","checks":{"database":"ok","cache":"ok"}}`, HTTP 200 em 9 ms |
| Alvos do Prometheus | Os 4 `up`, sem erro; `mysql_up` e `nginx_up` em 1 |
| Grafana | Versão 13.3.1, banco `ok`, o painel com os 13 itens (10 painéis e 3 linhas) |
| Loki | Logs dos 13 serviços. Na primeira hora, 36 linhas do `app`, 40 do `web` e 6 do `scheduler` |
| Erro de banco no Loki | Nas quedas do banco do roteiro de resiliência, 3 linhas `Erro no banco de dados.`, só com `connection`, `code`, `driver_code` e `file`, sem SQL, e-mail ou pilha (`docs/nuvem/SEGURANCA.md`, "LGPD") |
| Teste de carga | No estresse com 200 VUs, o cAdvisor (lido pelo Prometheus) mediu o pico do `app`: 1,92 núcleo de CPU e 57 MB de memória (`load-tests/README.md`, "Resultado na entrega final") |

## Problemas comuns

- **O alvo `mysql` está `UP`, mas o painel do MySQL está vazio** (`mysql_up` em 0, e o log do `mysqld-exporter` diz `Access denied for user 'exporter'`): o banco não tem o usuário `exporter`, ou ele tem outra senha. Siga "Banco que já existe".
- **`required variable HOMOLOG_GRAFANA_ADMIN_PASSWORD is missing a value`** (ou `HOMOLOG_DB_EXPORTER_PASSWORD`): o `homolog.env` é de antes do monitoramento ou o `--env-file` ficou de fora. Use a função `homologmon` e acrescente só a linha que falta com o comando de "Vindo do jeito antigo" do `docs/nuvem/DOCKER.md`, que começa com uma quebra de linha para não grudar na última linha do arquivo. A do exporter, num banco que já existe, pede também o roteiro "Banco que já existe".
- **Sem logs no Loki:** o Alloy lê o socket do Docker (`/var/run/docker.sock`). No Docker Desktop do Windows e do Mac ele existe; o Docker rootless do Linux não tem esse caminho, e o monitoramento de logs e o cAdvisor não funcionam nele.
- **`port is already allocated` na 3000 ou na 9090:** outro programa usa a porta. Confira com `docker ps` e com `Get-NetTCPConnection -LocalPort 3000 -State Listen` e pare o outro.
- **`Found orphan containers` ou `network ... has active endpoints` no `down`:** a stack subiu com a `homologmon` e desceu com a `homolog`. Rode `homologmon down`.
- **O painel de tempo de resposta está vazio, mas o de requisições não:** o log de acesso do Nginx mudou de formato. Veja "O painel".
