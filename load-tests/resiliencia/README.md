# Roteiro de resiliência

O roteiro derruba, um de cada vez, o banco, o app e o cache da homolog, mostra o que a aplicação responde enquanto isso e mede quanto tempo ela leva para voltar. É o ensaio local do que acontece na AWS quando o RDS reinicia ou troca de zona, quando o PHP-FPM de uma instância cai e quando o ElastiCache fica fora do ar.

**Situação:** medido em 14/11/2026 na homolog da branch `feat/observabilidade`, que não tem o `/api/health` (chega com a `feat/api-qualidade`). O app foi medido pelo `/up` e pelas telas; o banco e o cache, pelo `/up`, pelas telas e pela API. O `/api/health` em 503 e depois em 200, em cada caso, fica **verificado na F** (`feat/entrega-final`). Os números da seção "Medições" são desse dia e desse computador.

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
| `/api/health` | 503, com `"database": "falhou"` (verificado na F) | 200, assim que o MySQL aceita conexão |
| `/up` | 200: ele é raso e não consulta o banco. É o que o ALB olha, e por isso uma queda do banco não faz o Auto Scaling trocar instâncias | 200 |
| `/login` | 200: a tela não lê o banco, e a sessão fica no Redis | 200 |
| Telas e API que leem o banco | 500, depois de alguns segundos (o PHP desistindo da conexão) | 200 |
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
| `/api/health` | 503, com `"cache": "falhou"` (verificado na F) | 200 |
| `/up` | 200 | 200 |
| Telas (inclusive o `/login`) | 500, depois de alguns segundos: a sessão fica no Redis | 200 |
| API com token | 500: o limite de requisições guarda a contagem no cache (verificado na F) | 200 |

- **Tempo até voltar:** menos de 1 segundo depois do `start`: o Redis sobe rápido, e o Laravel conecta de novo na requisição seguinte.
- As sessões ficam no volume `redisdata` da homolog e sobrevivem ao `stop`. Na AWS, o ElastiCache não tem backup: se o cache perder os dados, quem estava logado entra de novo.
- **Na AWS:** na prod, com 2 nós e failover automático, a réplica assume; na homolog, com 1 nó, o cache fica fora até voltar. Avisam os alarmes de 5xx e os do cache.

## Medições

Em 14/11/2026, na homolog da `feat/observabilidade`, com o `curl` esperando até 75 segundos:

| Caso | O que respondeu | Tempo até voltar |
|---|---|---|
| PHP-FPM cai (`kill -TERM 1`) | O Docker reiniciou o container (`RestartCount` 1) | `/up` de novo 200 em 1,6 s desde a queda |
| App parado (`stop app`) | `/up` e `/login` com 504 em 2,0 s; `/favicon.ico` com 200 na hora | `/up` 200 em 1,7 s depois do `start` |
| App parado, antes do `fastcgi_connect_timeout` | A primeira requisição ficou presa 60 s até o 504, e a seguinte, 14 s até o 502 | Igual |
| Cache fora (`stop redis`) | `/up` 200; `/login` 500 em 4,0 s; `/api/services` 200 (nesta base, a API ainda não usa o cache) | `/login` 200 em 0,4 s depois do `start` |
| Banco fora (`stop db`) | `/up` e `/login` 200; `/api/services` 500 em 13,3 s | MySQL saudável em 8,8 s depois do `start` |

Nesta medição, o MySQL da homolog ficou num tmpfs (o volume `barberflow-homolog_dbdata` deste computador foi criado com uma senha que se perdeu, `docs/nuvem/DOCKER.md`, "Problemas comuns"), e o `stop db` apagou o banco: depois do `start`, foi preciso um `restart` do `app` para as migrations rodarem de novo. Com o volume de verdade, os dados continuam, e o `start` basta.

Os 13 segundos do banco fora e os 4 do cache fora são o tempo de o PHP desistir de achar o serviço parado (o nome dele some do DNS do Docker). Na AWS, o endereço do RDS e o do ElastiCache continuam existindo, e o tempo depende de como o serviço caiu.

## O que conferir na F

- O `/api/health` em 503 e depois em 200 nos três casos, com o tempo de cada um, e o `"database"` ou o `"cache"` com `falhou` na resposta.
- A API com token com o cache fora.
- Os mesmos três casos com o volume novo do banco da homolog, sem o tmpfs.
