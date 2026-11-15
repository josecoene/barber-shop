# Resultados do teste de carga (Sprint 4)

Executado em 29/10/2026 (noite, horário de Brasília) na máquina descrita em [AMBIENTE.md](AMBIENTE.md), com a stack local deste repositório.
Ferramenta: k6 1.3.0. Script: [barberflow.js](barberflow.js). Todos os números abaixo foram gerados a partir das execuções; nenhum foi estimado. O resumo de cada execução está em `results/*.json`. As tabelas por janela de 30 s vêm do log bruto de cada requisição (`--out json` do k6), que não vai no ZIP por tamanho (cerca de 200 MB); qualquer execução nova gera o seu em `results/ultima-execucao.raw.json`.

## Rodadas

- **baseline**: a stack como o grupo montou (Laravel em modo local com debug ligado, OPcache conferindo a data de cada arquivo a cada requisição, código montado a partir do disco do Windows).
- **otimizado**: mesma máquina e mesmos cenários, com `docker-compose.otimizado.yml`: `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize` (cache de configuração, rotas e views), `opcache.validate_timestamps=0` e validade do cache de caminhos (`realpath_cache_ttl`) de 120 para 600 s. O `realpath_cache_size=4096K` do arquivo já é o padrão da imagem e não mudou nada. Nenhuma linha de código da API mudou.

Antes de cada execução o banco foi recriado (`migrate:fresh --seed`). Nas duas rodadas o PHP-FPM ficou com o padrão da imagem: `pm.max_children = 5` (no máximo 5 requisições PHP ao mesmo tempo).

## Resumo

Metas: 95% das respostas em menos de 500 ms (p95) e menos de 1% de erros. VUs = usuários virtuais simultâneos.

| Rodada | Cenário | Requisições | Req/s | Mediana | p95 | p99 | Erros | VUs máx. | Metas (p95<500 ms, erros<1%) |
|---|---|---|---|---|---|---|---|---|---|
| baseline | smoke | 12 | 0,4 | 1,48 s | 3,63 s | 3,64 s | 0,00% | 1 | falhou |
| baseline | carga | 7561 | 27,9 | 431 ms | 852 ms | 931 ms | 0,00% | 50 | falhou |
| baseline | estresse | 9284 | 34,3 | 1,88 s | 3,96 s | 4,19 s | 0,00% | 200 | falhou |
| otimizado | carga | 10811 | 40,0 | 25 ms | 95 ms | 113 ms | 0,00% | 50 | passou |
| otimizado | estresse | 26495 | 97,9 | 17 ms | 24 ms | 32 ms | 0,00% | 200 | passou |

## Evolução durante os testes

Janelas de 30 s contadas desde o início de cada teste. "VUs (início a fim)" é o número de usuários no começo e no fim da janela (nas rampas ele muda dentro da janela).

#### baseline-carga (janelas de 30 s desde o início do teste)

| Janela | VUs (início a fim) | Req/s | p95 | Erros |
|---|---|---|---|---|
| 0 a 30 s | 0 a 25 | 10,2 | 684 ms | 0,0% |
| 30 a 60 s | 26 a 50 | 29,1 | 615 ms | 0,0% |
| 60 a 90 s | 50 a 50 | 34,5 | 741 ms | 0,0% |
| 90 a 120 s | 50 a 50 | 33,3 | 775 ms | 0,0% |
| 120 a 150 s | 50 a 50 | 33,1 | 786 ms | 0,0% |
| 150 a 180 s | 50 a 50 | 31,2 | 923 ms | 0,0% |
| 180 a 210 s | 50 a 50 | 31,7 | 862 ms | 0,0% |
| 210 a 240 s | 49 a 50 | 30,7 | 903 ms | 0,0% |
| 240 a 270 s | 1 a 49 | 18,2 | 799 ms | 0,0% |

Nenhuma janela passou de 1% de erros nem de p95 de 2 s.

#### baseline-estresse (janelas de 30 s desde o início do teste)

| Janela | VUs (início a fim) | Req/s | p95 | Erros |
|---|---|---|---|---|
| 0 a 30 s | 0 a 25 | 10,6 | 580 ms | 0,0% |
| 30 a 60 s | 26 a 50 | 31,5 | 489 ms | 0,0% |
| 60 a 90 s | 51 a 75 | 39,1 | 996 ms | 0,0% |
| 90 a 120 s | 76 a 100 | 39,3 | 1,54 s | 0,0% |
| 120 a 150 s | 101 a 125 | 38,8 | 2,19 s | 0,0% |
| 150 a 180 s | 126 a 150 | 39,0 | 2,83 s | 0,0% |
| 180 a 210 s | 151 a 175 | 39,0 | 3,49 s | 0,0% |
| 210 a 240 s | 176 a 200 | 38,9 | 4,10 s | 0,0% |
| 240 a 270 s | 1 a 198 | 33,3 | 4,34 s | 0,0% |

Ponto de quebra (primeira janela com erros > 1% ou p95 > 2 s): janela de 120 a 150 s, com 101 a 125 usuários (p95 2,19 s, erros 0,0%).

#### otimizado-carga (janelas de 30 s desde o início do teste)

| Janela | VUs (início a fim) | Req/s | p95 | Erros |
|---|---|---|---|---|
| 0 a 30 s | 0 a 25 | 12,9 | 27 ms | 0,0% |
| 30 a 60 s | 26 a 50 | 37,4 | 31 ms | 0,0% |
| 60 a 90 s | 50 a 50 | 48,6 | 43 ms | 0,0% |
| 90 a 120 s | 50 a 50 | 48,3 | 54 ms | 0,0% |
| 120 a 150 s | 50 a 50 | 47,9 | 73 ms | 0,0% |
| 150 a 180 s | 50 a 50 | 47,8 | 88 ms | 0,0% |
| 180 a 210 s | 50 a 50 | 47,6 | 90 ms | 0,0% |
| 210 a 240 s | 50 a 50 | 47,4 | 104 ms | 0,0% |
| 240 a 270 s | 1 a 48 | 22,5 | 115 ms | 0,0% |

Nenhuma janela passou de 1% de erros nem de p95 de 2 s.

#### otimizado-estresse (janelas de 30 s desde o início do teste)

| Janela | VUs (início a fim) | Req/s | p95 | Erros |
|---|---|---|---|---|
| 0 a 30 s | 0 a 25 | 12,9 | 25 ms | 0,0% |
| 30 a 60 s | 26 a 50 | 37,5 | 24 ms | 0,0% |
| 60 a 90 s | 51 a 75 | 62,0 | 22 ms | 0,0% |
| 90 a 120 s | 76 a 100 | 86,6 | 23 ms | 0,0% |
| 120 a 150 s | 101 a 125 | 111,2 | 25 ms | 0,0% |
| 150 a 180 s | 126 a 150 | 135,8 | 23 ms | 0,0% |
| 180 a 210 s | 151 a 175 | 159,9 | 27 ms | 0,0% |
| 210 a 240 s | 176 a 199 | 184,9 | 25 ms | 0,0% |
| 240 a 270 s | 5 a 191 | 92,4 | 23 ms | 0,0% |

Nenhuma janela passou de 1% de erros nem de p95 de 2 s.

## Achados

1. **O gargalo da linha de base é o tempo de cada requisição PHP, não a máquina.** No estresse, a vazão parou em cerca de 39 req/s a partir da faixa de 51 a 75 usuários e, dali em diante, mais usuários só aumentaram a fila: o p95 passou de 2 s na faixa de 101 a 125 usuários e chegou a 4,10 s na faixa de 176 a 200. No Grafana, o container `app` usou menos de 1 núcleo de CPU (a máquina tem 16) enquanto o nginx acumulava conexões. Com só 5 workers do PHP-FPM e cada requisição levando centenas de milissegundos, o teto fica perto do que foi medido. Print: `docs/prints/baseline-estresse-dashboard.png`.
2. **Configuração resolveu sem mudar código.** Na carga com 50 usuários o p95 caiu de 852 ms para 95 ms (cerca de 9 vezes menor) e as duas metas passaram. No estresse com 200 usuários o p95 caiu de 3,96 s para 24 ms, e a vazão deixou de ter teto: chegou a 184,9 req/s na faixa de 176 a 199 usuários, acompanhando o número de usuários. As mudanças foram aplicadas juntas, então não dá para dizer quanto cada uma contribuiu. A hipótese mais provável é que o maior ganho venha de não conferir a data dos arquivos a cada requisição: com o código montado do disco do Windows, cada conferência atravessa a ponte entre o Windows e o Linux do Docker. Print: `docs/prints/otimizado-estresse-dashboard.png`.
3. **Nenhum erro em nenhuma execução.** Foram 54.163 requisições no total, todas respondidas com sucesso (0,00% de erro). O sistema ficou lento sob pressão, mas não caiu.
4. **`GET /api/barbers` fica mais lento conforme a tabela cresce.** A rota devolve todos os barbeiros de uma vez (`Barber::all()`, sem paginação). No teste de carga, em que 10% das requisições cadastram barbeiros, a resposta chegou a 123 KB (print `docs/prints/logs-centralizados.png`) e o p95 da rodada otimizada subiu de 31 ms (janela de 30 a 60 s) para 104 ms (janela de 210 a 240 s), com o mesmo número de usuários. Em produção isso piora a cada cadastro. Recomendação: paginar (`Barber::paginate()`).
5. **Ponto de quebra.** Na linha de base, com o critério "erros acima de 1% ou p95 acima de 2 s", o sistema quebrou na faixa de **101 a 125 usuários simultâneos** (janela de 120 a 150 s). Na rodada otimizada não houve quebra até 200 usuários, o máximo testado.

## Limites deste teste

- Ambiente local (um computador), não a AWS. Os números servem para comparar as rodadas entre si, não como capacidade de produção.
- O k6 roda na mesma máquina que a aplicação e disputa CPU com ela.
- Cada usuário virtual espera 1 s entre as requisições, então 200 usuários geram no máximo cerca de 200 req/s.
