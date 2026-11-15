# Roteiro de resiliência

O roteiro derruba, um de cada vez, o banco, o app e o cache da homolog, mostra o que a aplicação responde enquanto isso e mede quanto tempo ela leva para voltar. É o ensaio local do que acontece na AWS quando o RDS reinicia ou troca de zona, quando o PHP-FPM de uma instância cai e quando o ElastiCache fica fora do ar.

**Situação:** medido duas vezes no mesmo computador. Em 14/11/2026, na homolog da branch `feat/observabilidade`, que ainda não tinha o `/api/health`, pelo `/up`, pelas telas e pela API. Em 15/11/2026, na entrega final (`feat/entrega-final`), com o código inteiro: o `/api/health` em 503 e depois em 200 nos três casos, a API com token, o banco congelado e os timeouts curtos (seção "Medições"). Na revisão final do mesmo dia, o banco caiu de novo com o MySQL num volume de verdade, e o `start` bastou. Falta só a AWS (seção "O que falta conferir").

## Antes de começar

- A homolog com o monitoramento no ar: `homologmon up -d --wait` (função `homologmon` do `docs/nuvem/MONITORAMENTO.md`). O Grafana (http://localhost:3000) mostra cada queda nos painéis de status, de erros e do MySQL.
- Dois terminais na raiz do repositório: o primeiro acompanha um endereço, o segundo derruba e volta o serviço.

No primeiro terminal, o script mostra cada mudança de código HTTP, com a hora e os segundos desde o começo (HTTP 000 é sem resposta nenhuma):

```powershell
.\load-tests\resiliencia\acompanhar.ps1
.\load-tests\resiliencia\acompanhar.ps1 -Url http://localhost:8080/up -Segundos 120
```

No Git Bash:

```bash
bash load-tests/resiliencia/acompanhar.sh
bash load-tests/resiliencia/acompanhar.sh http://localhost:8080/up 120
```

Sem argumento, ele acompanha o `/api/health` por 5 minutos. O tempo até voltar é a diferença entre a linha que muda para 200 e a hora em que o comando de volta (o `start`) terminou no segundo terminal.

## 1. Banco fora

No segundo terminal:

```powershell
homologmon stop db
```

Espere uns 30 segundos olhando o primeiro terminal e o Grafana, e volte o banco:

```powershell
homologmon start db
```

| Endereço | Com o banco fora | Depois do `start` |
|---|---|---|
| `/api/health` | 503, com `"database": "falhou"` (13,3 s em 15/11/2026, quase tudo procurando o nome do serviço parado no DNS do Docker) | 200, assim que o MySQL aceita conexão |
| `/up` | 200: ele é raso e não consulta o banco. É o que o ALB olha, e por isso uma queda do banco não faz o Auto Scaling trocar instâncias | 200 |
| `/login` | 200: a tela não lê o banco, e a sessão fica no Redis | 200 |
| Telas e API que leem o banco | 500, depois de alguns segundos (o PHP desistindo da conexão; a API com token levou 12,4 s) | 200 |
| Grafana | `mysql_up` em 0 e o painel do MySQL vazio; o painel "Erros da aplicação" sobe | Volta sozinho |

- **Tempo até voltar:** o do MySQL subir, perto de 10 segundos. Nada no app precisa reiniciar: o Laravel abre uma conexão nova a cada requisição.
- **Na AWS:** na prod, com Multi-AZ, o RDS troca de zona sozinho em 1 a 2 minutos (`docs/nuvem/BACKUP.md`); na homolog, sem réplica, o banco fica fora até voltar. Os alarmes que avisam são os de 5xx das instâncias e os do RDS (`docs/nuvem/TERRAFORM.md`, "Observabilidade").

## 2. App morta

Dois jeitos, e o primeiro é o mais comum na vida real.

**O PHP-FPM cai** (um erro fatal derruba o processo principal):

```powershell
homologmon exec app sh -c 'kill -TERM 1'
```

O container sai, e o Docker sobe de novo sozinho, porque o compose de produção tem `restart: unless-stopped`. Ninguém precisa fazer nada.

**O app parado à mão**, para ver a queda por mais tempo:

```powershell
homologmon stop app
homologmon start app
```

| Endereço | Com o app parado | Depois do `start` |
|---|---|---|
| `/up`, `/login`, a API e o `/api/health` | 504 em 2 segundos: o Nginx não alcança o PHP-FPM (`fastcgi_connect_timeout` de 2 s, `docs/nuvem/DOCKER.md`, "Nginx"). No instante da queda, enquanto o container ainda existe, 502 na hora | 200 |
| Arquivos estáticos (`/build/...`, `/favicon.ico`) | 200: o Nginx entrega sozinho | 200 |

- **Tempo até voltar:** perto de 2 segundos, o tempo de o entrypoint do `app` rodar o `php artisan optimize` (e as migrations, que não têm nada novo) e de o PHP-FPM abrir.
- O `scheduler` é outro container e continua no ar. Quando o compose recria o `app` (um `up` com configuração nova), ele reinicia o `web` junto (`depends_on` com `restart: true`), para o Nginx pegar o endereço novo.
- **Na AWS:** o PHP-FPM que cai volta sozinho do mesmo jeito, em segundos. Se o `app` não voltar, o `/up` da instância responde 504, o ALB dá a instância como doente depois de 3 falhas (45 segundos) e para de mandar tráfego para ela, e o Auto Scaling troca a instância (fora dos 300 segundos de carência depois que ela nasce). Avisam os alarmes de 5xx e de instâncias sem saúde.

## 3. Cache fora

```powershell
homologmon stop redis
homologmon start redis
```

| Endereço | Com o cache fora | Depois do `start` |
|---|---|---|
| `/api/health` | 503, com `"cache": "falhou"`, em 3 a 4 s (o `REDIS_CONNECT_TIMEOUT` de 3 s) | 200 |
| `/up` | 200 | 200 |
| Telas (inclusive o `/login`) | 500 em cerca de 3 s: a sessão fica no Redis | 200 |
| API com token | 500 em cerca de 3 s: o limite de requisições guarda a contagem no cache | 200 |

- **Tempo até voltar:** menos de 1 segundo depois do `start`: o Redis sobe rápido, e o Laravel conecta de novo na requisição seguinte.
- As sessões ficam no volume `redisdata` da homolog e sobrevivem ao `stop`. Na AWS, o ElastiCache não tem backup: se o cache perder os dados, quem estava logado entra de novo.
- **Na AWS:** na prod, com 2 nós e failover automático, a réplica assume; na homolog, com 1 nó, o cache fica fora até voltar. Avisam os alarmes de 5xx e os do cache.

## Medições

### Na entrega final (15/11/2026)

Na homolog da `feat/entrega-final`, com os limites normais e o monitoramento, o `curl` esperando até 75 segundos e a API com token de um login do admin feito antes. Primeiro os casos que não apagam nada; por último o `stop db`, que apaga o banco do tmpfs.

| Caso | `/api/health` durante a queda | `/up` | `/login` | API com token (`/api/services`) | Volta |
|---|---|---|---|---|---|
| Antes | 200 em 0,01 s | 200 | 200 | 200 | |
| Cache congelado (`homologmon pause redis`) | **503 em 3,02 s**, `"cache":"falhou"` | 200 | 500 em 3,01 s | 500 em 3,02 s | 200 logo depois do `unpause` |
| Cache fora (`homologmon stop redis`) | **503 em 3,94 s**, `"cache":"falhou"` | 200 | 500 em 3,12 s | 500 em 3,11 s | 200 logo depois do `start`; `/login` e API com token 200 |
| PHP-FPM cai (`kill -TERM 1`) | | 502 na hora | | | `/up` 200 em **1,7 s** desde o kill (o Docker reiniciou o container) |
| App parado (`stop app`) | 504 em 2,00 s | 504 em 2,00 s | 504 em 2,00 s | | `/favicon.ico` 200 o tempo todo; `/api/health` 200 em 0,7 s depois do `start` |
| Banco congelado (`pause db`) | **504 em 60,00 s** | 200 | 200 | 504 em 60,06 s | `/api/health` 200 logo depois do `unpause` |
| Banco fora (`stop db`) | **503 em 13,30 s**, `"database":"falhou"` | 200 | 200 | 500 em 12,43 s | MySQL saudável em **8,8 s** depois do `start`; `/api/health` 200 na hora |

- O `/up` respondeu 200 em todos os casos, menos com o app fora: é raso, como o ALB precisa.
- **Banco congelado:** com o MySQL de pé e sem responder, a conexão TCP abre e o PHP espera a resposta sem limite curto. O Nginx desiste em 60 s (o `fastcgi_read_timeout`), e o processo do PHP-FPM fica preso até o banco voltar. Nenhuma linha de erro entra no log, porque a requisição não falha: ela fica presa. O `DB_CONNECT_TIMEOUT` só vale para abrir a conexão; um limite de leitura do `mysqlnd` resolveria e ficou em aberto (`docs/ARQUITETURA.md`, "Limitações e riscos conhecidos").
- **Banco fora:** depois do `start`, a API com o token antigo deu 500 até as tabelas voltarem, porque o tmpfs apagou o banco. Um `homologmon restart app` rodou as migrations de novo (`/api/health` 200 em 4 s), e o `DemoSeeder` e o `AdminUserSeeder` refizeram os dados. Com o volume de verdade, o `start` basta (seção seguinte).
- **Erro de banco no log:** nas quedas do banco entraram no Loki 3 linhas `Erro no banco de dados.`, só com a conexão, os códigos (2002, de conexão, duas vezes, e `42S02`/1146, de tabela que não existe) e o arquivo:linha do projeto. Nenhuma tem SQL, e-mail ou pilha.

**O timeout curto, medido à parte.** O mesmo código do `/api/health` rodou num container descartável do `app`, com o `DB_HOST` ou o `REDIS_HOST` apontando para um container de teste que descarta toda conexão nova sem responder (como um RDS ou um ElastiCache inalcançável):

| Caso | `/api/health` |
|---|---|
| Banco no endereço que descarta, `DB_CONNECT_TIMEOUT` padrão (3) | **503 em 12,03 s** (o Laravel tenta 4 vezes: 4 vezes 3 s) |
| Banco no endereço que descarta, `DB_CONNECT_TIMEOUT=10` | 503 em 40,05 s |
| Cache no endereço que descarta, `REDIS_CONNECT_TIMEOUT` padrão (3) | **503 em 3,02 s** |
| Cache no endereço que descarta, `REDIS_CONNECT_TIMEOUT=10` | 503 em 10,02 s |
| Nome que não existe na rede do Docker (`getent hosts`) | 4,61 s por consulta, que nenhum timeout limita |

Por isso o banco fora (`stop db`) levou 13,3 s: o nome do serviço parado some do DNS do Docker, e quase todo o tempo é a procura pelo nome. Na AWS, o endereço do RDS continua existindo, e vale a linha "endereço que descarta".

### Banco fora com o volume de verdade (15/11/2026, revisão final)

Na homolog da `feat/entrega-final`, sem o monitoramento, com o MySQL num volume novo e persistente (`barberflow-homolog_dbdata-r`; o `barberflow-homolog_dbdata` deste computador tem a senha perdida e ficou intocado), os dados do `DemoSeeder` (177 agendamentos e 95 pagamentos) e um token do barbeiro Carlos Mendes, de um login feito antes:

| Caso | `/api/health` durante a queda | API com token (`/api/appointments`) | Volta |
|---|---|---|---|
| Banco fora (`homolog stop db`) | **503 em 13,3 s** | 500 | `/api/health` 200 em **1,9 s** depois do `start`, sem `restart` do app, sem migrations e sem seeders; os mesmos 177 agendamentos e 95 pagamentos, e o mesmo token com 200 |

- É o que o tmpfs não deixava ver: os dados ficam no volume, e o `start` basta.
- Só o caso do banco muda com o volume. A queda do app e a do cache não dependem de onde o MySQL guarda os dados, e valem os números da tabela de cima.
- Na mesma rodada, o "subir de novo sem perder nada" (go-live A15): `homolog down`, sem `-v`, e `homolog up -d --wait`, com os mesmos dados, o token e a sessão do navegador valendo (`docs/nuvem/go-live-checklist.md`).

### Na `feat/observabilidade` (14/11/2026)

Na homolog da `feat/observabilidade`, com o `curl` esperando até 75 segundos:

| Caso | O que respondeu | Tempo até voltar |
|---|---|---|
| PHP-FPM cai (`kill -TERM 1`) | O Docker reiniciou o container (`RestartCount` 1) | `/up` de novo 200 em 1,6 s desde a queda |
| App parado (`stop app`) | `/up` e `/login` com 504 em 2,0 s; `/favicon.ico` com 200 na hora | `/up` 200 em 1,7 s depois do `start` |
| App parado, antes do `fastcgi_connect_timeout` | A primeira requisição ficou presa 60 s até o 504, e a seguinte, 14 s até o 502 | Igual |
| Cache fora (`stop redis`) | `/up` 200; `/login` 500 em 4,0 s; `/api/services` 200 (nesta base, a API ainda não usa o cache) | `/login` 200 em 0,4 s depois do `start` |
| Banco fora (`stop db`) | `/up` e `/login` 200; `/api/services` 500 em 13,3 s | MySQL saudável em 8,8 s depois do `start` |

Nesta medição, o MySQL da homolog ficou num tmpfs (o volume `barberflow-homolog_dbdata` deste computador foi criado com uma senha que se perdeu, `docs/nuvem/DOCKER.md`, "Problemas comuns"), e o `stop db` apagou o banco: depois do `start`, foi preciso um `restart` do `app` para as migrations rodarem de novo. Com o volume de verdade, os dados continuam, e o `start` basta.

Os 13 segundos do banco fora e os 4 do cache fora são o tempo de o PHP desistir de achar o serviço parado (o nome dele some do DNS do Docker). Na AWS, o endereço do RDS e o do ElastiCache continuam existindo, e o tempo depende de como o serviço caiu. Os timeouts curtos que entraram depois (3 s para o cache e 3 s por tentativa para o banco) estão medidos na seção acima.

## O que falta conferir

- Os três casos na AWS, depois do primeiro `apply`, com o RDS e o ElastiCache de verdade.

O volume `barberflow-homolog_dbdata` deste computador, com a senha perdida, continua sem uso: a medição com volume usou um volume novo (`barberflow-homolog_dbdata-r`). Apagar ou trocar o antigo, e apagar o novo se ele não for mais usado, espera o OK do Leonam.
