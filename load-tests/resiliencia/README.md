# Roteiro de resiliência

O roteiro derruba, um de cada vez, o banco, o app e o cache da homolog, mostra o que a aplicação responde enquanto isso e mede quanto tempo ela leva para voltar. É o ensaio local do que acontece na AWS quando o RDS reinicia ou troca de zona, quando o PHP-FPM de uma instância cai e quando o ElastiCache fica fora do ar.

**Situação:** medido no mesmo computador em 14 e 15/11/2026. Em 14/11/2026, na homolog da branch `feat/observabilidade`, que ainda não tinha o `/api/health`, pelo `/up`, pelas telas e pela API. Em 15/11/2026, na entrega final (`feat/entrega-final`), com o código inteiro: o `/api/health` em 503 e depois em 200 nos três casos, a API com token, o banco congelado e os timeouts curtos (seção "Medições"). Na revisão final do mesmo dia, o banco caiu de novo com o MySQL num volume de verdade, e o `start` bastou. Na rodada de acabamento, ainda em 15/11, com o limite de leitura do MySQL (`DB_READ_TIMEOUT`), o banco congelado passou de 504 em 60 s para 503 em 20 s, e o servidor de e-mail caiu e congelou. Na segunda rodada de acabamento, com o limite de tempo do servidor de e-mail (`MAIL_TIMEOUT`), o servidor de e-mail congelado passou de 60 s para 10 s, e a falha do lembrete passou a aparecer no log do agendador. Falta só a AWS (seção "O que falta conferir").

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
| Banco congelado (`pause db`), antes do limite de leitura | **504 em 60,00 s** | 200 | 200 | 504 em 60,06 s | `/api/health` 200 logo depois do `unpause` |
| Banco fora (`stop db`) | **503 em 13,30 s**, `"database":"falhou"` | 200 | 200 | 500 em 12,43 s | MySQL saudável em **8,8 s** depois do `start`; `/api/health` 200 na hora |

- O `/up` respondeu 200 em todos os casos, menos com o app fora: é raso, como o ALB precisa.
- **Banco congelado, antes do limite de leitura:** com o MySQL de pé e sem responder, a conexão TCP abre e o PHP esperava a resposta sem limite curto. O Nginx desistia em 60 s (o `fastcgi_read_timeout`), e o processo do PHP-FPM ficava preso até o banco voltar. Nenhuma linha de erro entrava no log, porque a requisição não falhava: ficava presa. O `DB_CONNECT_TIMEOUT` só vale para abrir a conexão. Por causa desta medição, entrou o limite de leitura do `mysqlnd` nas requisições web (`DB_READ_TIMEOUT`, padrão 5 s, `docs/ARQUITETURA.md`, "Quedas do banco e do cache"), e o mesmo caso passou a dar 503 em 20 s (seção "Com o limite de leitura do MySQL", adiante).
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

Na homolog da `feat/entrega-final`, sem o monitoramento, com o MySQL num volume novo e persistente (`barberflow-homolog_dbdata-r`; o `barberflow-homolog_dbdata` deste computador tinha a senha perdida e ficou intocado; os dois foram apagados depois, em 15/11/2026, com o OK do Leonam), os dados do `DemoSeeder` (177 agendamentos e 95 pagamentos) e um token do barbeiro Carlos Mendes, de um login feito antes:

| Caso | `/api/health` durante a queda | API com token (`/api/appointments`) | Volta |
|---|---|---|---|
| Banco fora (`homolog stop db`) | **503 em 13,3 s** | 500 | `/api/health` 200 em **1,9 s** depois do `start`, sem `restart` do app, sem migrations e sem seeders; os mesmos 177 agendamentos e 95 pagamentos, e o mesmo token com 200 |

- É o que o tmpfs não deixava ver: os dados ficam no volume, e o `start` basta.
- Só o caso do banco muda com o volume. A queda do app e a do cache não dependem de onde o MySQL guarda os dados, e valem os números da tabela de cima.
- Na mesma rodada, o "subir de novo sem perder nada" (go-live A15): `homolog down`, sem `-v`, e `homolog up -d --wait`, com os mesmos dados, o token e a sessão do navegador valendo (`docs/nuvem/go-live-checklist.md`).

### Com o limite de leitura do MySQL (15/11/2026, rodada de acabamento)

Na homolog da `feat/entrega-final`, com as imagens refeitas com as correções da rodada de acabamento (entre elas, o limite de leitura do MySQL, `DB_READ_TIMEOUT` de 5 s, e o cadastro de conta que não depende do e-mail), sem o monitoramento, com o MySQL no volume `barberflow-homolog_dbdata-r` e os dados do `DemoSeeder` (177 agendamentos e 95 pagamentos). A tela com sessão é o `GET /dashboard` do admin, logado pelo formulário; a API com token, o `GET /api/appointments?per_page=1` de um login do barbeiro Carlos Mendes. O `curl` esperou até 75 segundos.

| Caso | `/api/health` durante a queda | `/up` | `/login` | Tela com sessão (`/dashboard`) | API com token (`/api/appointments`) | Volta |
|---|---|---|---|---|---|---|
| Antes | 200 em 0,01 s | 200 em 0,00 s | 200 em 0,01 s | 200 em 0,01 s | 200 em 0,02 s | |
| Banco congelado (`homolog pause db`) | **503 em 20,05 s**, `"database":"falhou"` | 200 em 0,01 s | 200 em 0,01 s | **500 em 20,03 s**, a página "Erro no servidor" | **500 em 20,03 s** | `/api/health` 200 em 0,03 s depois do `unpause`; a mesma sessão e o mesmo token com 200 |
| Cache congelado (`homolog pause redis`) | **503 em 3,01 s**, `"cache":"falhou"` | 200 em 0,01 s | 500 em 3,01 s | 500 em 3,01 s | 500 em 3,02 s | `/api/health` 200 em 0,02 s depois do `unpause` |
| Banco fora (`homolog stop db`) | **503 em 13,31 s**, `"database":"falhou"` | 200 em 0,01 s | 200 em 0,01 s | 500 em 12,50 s | 500 em 12,48 s | `/api/health` 200 em **1,19 s** depois do `start`, sem `restart` do app, migrations ou seeders, com os mesmos dados |

- **Banco congelado:** o 503 do `/api/health` e o 500 da tela e da API saíram em 20 s, um terço dos 60 s do Nginx. São as 4 tentativas do Laravel (ele repete a conexão e a consulta quando a conexão parece perdida) vezes os 5 s do limite. Antes dele, o mesmo caso dava 504 em 60 s (tabela "Na entrega final").
- **O PHP-FPM fica livre:** cada processo desiste em cerca de 20 s e volta ao pool com o banco ainda congelado. Logo depois das três medidas, o `app` não tinha nenhuma conexão aberta com a 3306 (contadas por estado no `/proc/net/tcp` do container), e o ping do PHP-FPM respondeu em 0,12 s. Antes do limite, o processo ficava preso até o banco voltar.
- **Pool cheio:** 10 pedidos ao `/api/health` ao mesmo tempo, o tamanho do pool (`pm.max_children`), ocuparam os 10 processos, com 10 conexões abertas com a 3306. Um `/up` mandado nesse instante esperou um processo livre: 200 em **16,79 s**. Os 10 responderam 503 entre 20,02 e 23,11 s, e logo depois o `/up` voltou a 0,01 s, ainda com o banco congelado. Na AWS, o health check do ALB espera o `/up` por 5 s, e 3 falhas seguidas tiram a instância: com o banco congelado e um tráfego que mantenha o pool cheio, as instâncias podem ser dadas como doentes e trocadas (`docs/ARQUITETURA.md`, "Limitações e riscos conhecidos"). Antes do limite era pior: 10 pedidos durante a queda, em qualquer ritmo, prendiam o pool inteiro até o banco voltar.
- **Log:** uma linha `Erro no banco de dados.` por pedido que falhou, com o código 2006 ("MySQL server has gone away", a leitura que desistiu) e o arquivo:linha, sem SQL e sem e-mail. Antes do limite, o banco congelado não deixava nenhuma linha.
- **Nada piorou:** o cache congelado e o banco fora deram os mesmos números da tabela "Na entrega final", e os dados ficaram iguais depois do `stop` e do `start`.

**Servidor de e-mail fora** (`homolog stop mailpit`), com duas contas de cliente novas, uma pela API e outra pela tela `/register`, com e-mails do domínio `demo.barberflow.test`:

| Pedido, com o Mailpit fora | Resposta | Tempo |
|---|---|---|
| `POST /api/register` | **201**, com o aviso `Conta criada, mas não foi possível enviar o e-mail de confirmação agora. Peça um novo link em alguns minutos.` | 4,31 s |
| `POST /api/email/verification-notification`, com o token da conta nova | **503**, `Não foi possível enviar o link de confirmação agora. Tente de novo em alguns minutos.` | 3,13 s |
| Tela `/register`, botão "Criar conta" | Entrou na tela "Confirme seu e-mail", já logada, com o aviso amarelo de que o link não saiu | 4,96 s |
| Botão "Reenviar o link" da mesma tela | Voltou à tela com o aviso amarelo | 5,06 s |

- Antes da rodada de acabamento, o cadastro e o reenvio respondiam 500 com o e-mail fora do ar, com a conta já criada.
- O log ganhou 5 linhas `Falha no envio de e-mail.` (os 2 cadastros e 3 reenvios), sem nenhum endereço.
- Os 3 a 5 s são quase todos a procura do nome `mailpit`, que some do DNS do Docker com o `stop`, como no banco fora.
- Com o Mailpit de volta (`start`), o reenvio deu 202 em 0,08 s, e o e-mail chegou. As duas contas ficaram sem confirmar e não viraram cliente: o cadastro de cliente só nasce na confirmação do e-mail.

**Servidor de e-mail congelado** (`homolog pause mailpit`): o reenvio do link levou **60,03 s** até o 503. O SMTP do Laravel estava sem limite de tempo (`config/mail.php`, `'timeout' => null`), então a leitura esperava o `default_socket_timeout` do PHP, de 60 s, o mesmo número do `fastcgi_read_timeout` do Nginx, que podia responder 504 no lugar do 503. O processo do PHP-FPM ficava preso esse tempo, e a rodada do lembrete do agendador que caiu no meio esperou 55 s. Era um achado em aberto, corrigido na segunda rodada de acabamento (seção seguinte).

### Com o limite de tempo do servidor de e-mail (15/11/2026, segunda rodada de acabamento)

Na homolog da `feat/entrega-final`, com as imagens refeitas com as correções da segunda rodada de acabamento (entre elas, o limite de tempo do SMTP, `MAIL_TIMEOUT` de 10 s, e a rodada do lembrete dentro do processo do agendador), sem o monitoramento, com o MySQL no volume `barberflow-homolog_dbdata-r` e os mesmos dados. O Mailpit congelado com `homolog pause mailpit`: a conexão TCP abre, e a saudação do SMTP não chega. Os pedidos (pelo `Invoke-WebRequest` do PowerShell) esperaram até 75 segundos.

| Pedido, com o Mailpit congelado | Antes do limite (rodada de acabamento) | Com o limite |
|---|---|---|
| `POST /api/email/verification-notification` (reenvio do link) | 503 em 60,03 s | **503 em 10,04 s e 10,03 s** |
| `POST /api/register` | Não medido (o mesmo limite de 60 s) | **201 em 10,42 s**, com o aviso de que o link não saiu |
| `POST /api/appointments/{id}/reminder` ("Enviar lembrete agora") | Não medido (o mesmo limite de 60 s) | **503 em 10,06 s**, com o `reminder_sent_at` de volta a nulo |
| Rodada do lembrete do agendador | 55 s presa num lembrete só, até o `unpause` | **30 s para 3 lembretes**, 10 s cada |

- O envio desiste nos 10 s do `MAIL_TIMEOUT` (a espera pela saudação do SMTP), um sexto dos 60 s do Nginx. O cadastro leva 0,4 s a mais que o reenvio: é a gravação da conta, com o hash da senha, antes do envio.
- **Log do `app`:** 3 linhas `Falha no envio de e-mail.` (o cadastro e os dois reenvios), só com a classe, o código e o arquivo:linha, sem o endereço e sem o texto do erro do Symfony.
- **Log do `scheduler`** (`homolog logs scheduler`), na rodada das 13h45, com o Mailpit congelado: 3 avisos `Lembrete por e-mail não enviado.`, cada um só com o `appointment_id`, e 1 linha `ERROR` só com as contas (`Lembretes por e-mail com falha nesta rodada: 3. Enviados: 0. Pulados: 0.`), sem nenhum nome, e-mail ou telefone. O `reminder_sent_at` dos 3 voltou a nulo. Antes, o comando agendado tinha a saída no `/dev/null`, e nada disso aparecia.
- **A linha da rodada saiu `30s DONE`, e não `FAIL`,** com a rodada falhando: é do Laravel 13.33, e continua na 13.40 (seção seguinte e `docs/ARQUITETURA.md`, "Limitações e riscos conhecidos"). Quem acompanha o agendador procura a linha `ERROR`.
- **Volta:** com o `unpause`, o reenvio deu 202 em 0,04 s, e o link chegou; a rodada das 14h00 mandou os 3 lembretes em 0,23 s.
- **Não medido aqui:** o servidor que congela no meio da conversa (até 20 s, porque o comando sem resposta e o `QUIT` esperam o limite cada um). O `pause` do Docker congela o Mailpit antes da saudação, para toda conexão nova. Numa sonda no container local, esse caso levou 20,04 s.

### A rodada do lembrete com o Laravel 13.40.0 (15/11/2026)

Na homolog com o `laravel/framework` 13.40.0, as imagens novas, o monitoramento e o MySQL no volume padrão, criado de novo nessa rodada (`docs/RELATORIO-TECNICO.md`, seção 4.12), pelas rodadas de verdade do `schedule:work`, sem nada rodado na mão. O Mailpit foi congelado (`homologmon pause mailpit`) às 19h44, antes da rodada das 19h45, com os 8 lembretes de 16/11 do `DemoSeeder` pendentes:

| Medida | Segunda rodada de acabamento (13.33.0) | Laravel 13.40.0 |
|---|---|---|
| Rodada com o Mailpit congelado | 3 lembretes em 30 s, `30s DONE` | 8 lembretes em 1 min 20 s (10 s cada), `1min 20s DONE` |
| Log da falha | 3 avisos com o id e 1 `ERROR` com as contas | 8 avisos com o id e 1 `ERROR` com as contas, também no Loki |
| `reminder_sent_at` depois da falha | Nulo | Nulo |
| Rodada seguinte, com o Mailpit de volta | 3 lembretes em 230,61 ms | 8 lembretes em 412,55 ms |

- A linha da rodada continua `DONE`, e não `FAIL`, com a 13.40.0: quem acompanha o agendador procura o `ERROR`.
- Nenhum nome, e-mail ou telefone dos 15 clientes e das 8 contas nas 46 linhas do `scheduler` da rodada que falhou.
- As quedas do banco, do app e do cache não foram medidas de novo com a 13.40.0.

### Na `feat/observabilidade` (14/11/2026)

Na homolog da `feat/observabilidade`, com o `curl` esperando até 75 segundos:

| Caso | O que respondeu | Tempo até voltar |
|---|---|---|
| PHP-FPM cai (`kill -TERM 1`) | O Docker reiniciou o container (`RestartCount` 1) | `/up` de novo 200 em 1,6 s desde a queda |
| App parado (`stop app`) | `/up` e `/login` com 504 em 2,0 s; `/favicon.ico` com 200 na hora | `/up` 200 em 1,7 s depois do `start` |
| App parado, antes do `fastcgi_connect_timeout` | A primeira requisição ficou presa 60 s até o 504, e a seguinte, 14 s até o 502 | Igual |
| Cache fora (`stop redis`) | `/up` 200; `/login` 500 em 4,0 s; `/api/services` 200 (nesta base, a API ainda não usa o cache) | `/login` 200 em 0,4 s depois do `start` |
| Banco fora (`stop db`) | `/up` e `/login` 200; `/api/services` 500 em 13,3 s | MySQL saudável em 8,8 s depois do `start` |

Nesta medição, o MySQL da homolog ficou num tmpfs (o volume `barberflow-homolog_dbdata` deste computador foi criado com uma senha que se perdeu, `docs/nuvem/DOCKER.md`, "Problemas comuns"; ele foi apagado em 15/11/2026), e o `stop db` apagou o banco: depois do `start`, foi preciso um `restart` do `app` para as migrations rodarem de novo. Com o volume de verdade, os dados continuam, e o `start` basta.

Os 13 segundos do banco fora e os 4 do cache fora são o tempo de o PHP desistir de achar o serviço parado (o nome dele some do DNS do Docker). Na AWS, o endereço do RDS e o do ElastiCache continuam existindo, e o tempo depende de como o serviço caiu. Os timeouts curtos que entraram depois (3 s para o cache e 3 s por tentativa para o banco) estão medidos na seção acima.

## O que falta conferir

- Os três casos na AWS, depois do primeiro `apply`, com o RDS e o ElastiCache de verdade.
- O servidor de e-mail que congela no meio da conversa, na homolog: até aqui, só a sonda local (20,04 s).
- As quedas do banco, do app e do cache com o Laravel 13.40.0: a rodada da atualização mediu só a rodada do lembrete com o servidor de e-mail congelado.

Os dois volumes do banco da homolog deste computador, o `barberflow-homolog_dbdata` da senha perdida e o `barberflow-homolog_dbdata-r` das medições com volume (revisão final e as duas rodadas de acabamento), foram apagados em 15/11/2026, com o OK do Leonam. A homolog voltou ao volume padrão (`barberflow-homolog_dbdata`), criado de novo na rodada do Laravel 13.40.0 com a senha do `homolog.env` e com os dados de demonstração: nele, só a rodada do lembrete com o servidor de e-mail congelado foi medida, e as quedas do banco, do app e do cache, não.
