# Checklist de go-live

Este checklist diz o que conferir antes de abrir o BarberFlow para clientes de verdade. Ele parte do checklist da Sprint 4 (12 itens, executados em 29/10/2026 na stack local daquela época; o original está no relatório, `docs/nuvem/sprint4/relatorio.html`) e foi adaptado para a stack de agora: a homolog com as imagens de produção, o monitoramento, os testes de carga com login, a resiliência e a AWS.

São duas partes:

- **A. Homologação local**, antes de aplicar o Terraform: a homolog roda as mesmas imagens que vão para a AWS (`docs/nuvem/DOCKER.md`).
- **B. AWS**, depois do primeiro `apply`, com a conta do grupo.

A coluna "Situação" diz como cada item estava em 15/11/2026, na entrega final (`feat/entrega-final`), que juntou a trilha da aplicação (login, `/api/health`, agenda e pagamentos) à de nuvem e rodou a verificação completa. Os números estão no `docs/RELATORIO-TECNICO.md`, seção "Evidências da verificação". Em todos os itens menos o A15, o MySQL da homolog ficou num tmpfs, porque o volume `barberflow-homolog_dbdata` deste computador tinha uma senha que se perdeu. O A15 e a queda do banco com o volume (no A11) rodaram depois, na revisão final do mesmo dia, com o MySQL num volume novo (`barberflow-homolog_dbdata-r`); o volume antigo ficou intocado. Depois, na rodada de acabamento, também em 15/11, a homolog subiu de novo nesse volume, com as imagens refeitas, e as quedas do banco e do cache e o servidor de e-mail fora do ar foram medidos outra vez (A4, A5 e A11). Na segunda rodada de acabamento, ainda em 15/11, ela subiu mais uma vez, do mesmo jeito, para medir o servidor de e-mail congelado com o limite de tempo novo (`MAIL_TIMEOUT`) e a falha do lembrete no log do agendador (A5 e A11). Por último, na rodada do Laravel 13.40.0, ainda em 15/11, os dois volumes do banco da homolog foram apagados, com o OK do Leonam, e a homolog subiu do zero no volume padrão (`barberflow-homolog_dbdata`, criado de novo nessa subida, com a senha do `homolog.env`), com as imagens novas e o monitoramento: do A1 ao A8, o A12, o A13 e o A15 rodaram de novo, o A9 só com o smoke, o A10 só com 10 pedidos e o A11 só com a rodada do lembrete com o servidor de e-mail congelado (`docs/RELATORIO-TECNICO.md`, seção 4.12).

## A. Homologação local

| # | Item | Como conferir | Situação em 15/11/2026 |
|---|---|---|---|
| A1 | A stack sobe do zero | Banco novo, `homolog up -d --build --wait` (ou `homologmon`, com o monitoramento) | Ok: com o monitoramento, os 13 containers no ar em 18,2 s, com as migrations rodando na subida. Na rodada do Laravel 13.40.0, do zero no volume padrão, que nasceu nessa subida: os 13 em 20,1 s, as 15 migrations e o `/api/health` em 200, com o app entrando no banco novo com a senha do `homolog.env` |
| A2 | O `/up` responde 200 | `curl.exe -I http://localhost:8080/up` | Ok (de novo na rodada do Laravel 13.40.0: 200 em 6 ms) |
| A3 | O `/api/health` responde 200, com banco e cache | `curl.exe -i http://localhost:8080/api/health` | Ok: `{"status":"ok","checks":{"database":"ok","cache":"ok"}}`, em 9 ms; na rodada do Laravel 13.40.0, em 8 ms |
| A4 | Login e telas de cada perfil (admin, barbeiro e cliente) | Navegador em http://localhost:8080/login, com o `DemoSeeder` (`docs/nuvem/DOCKER.md`, "Dados de demonstração e administrador") | Ok: o admin abriu as 8 telas com a API em 200 e o console vazio, com a CSP ligada (e 7 delas de novo na rodada de acabamento, com as imagens refeitas); os 44 prints das telas dos 3 perfis e do visitante estão em `docs/prints-telas/`, 22 deles refeitos na rodada de acabamento. Na rodada do Laravel 13.40.0, os três perfis pela API e no navegador: o admin nas 8 telas, o barbeiro nas 5 dele e a cliente nas 3 dela, com a página "Acesso negado" nas dos outros perfis, 32 chamadas à API em 200 e o console vazio |
| A5 | Suíte de testes da aplicação | `composer test` e `composer test:mysql` (CI, jobs `PHP` e `MySQL`) | Ok: 747 passaram e 11 pulados no SQLite (os de trava, que só valem no MySQL) e 758 no MySQL 8.4, nenhum pulado (depois da segunda rodada de acabamento); a rodada igual à do CI, numa cópia sem `vendor` e com o `.env` do `.env.example`, também passou, com os mesmos 747; com o `laravel/framework` 13.40.0, os mesmos números nas três rodadas |
| A6 | Coleta de métricas | Os 4 alvos `UP` no Prometheus (`docs/nuvem/MONITORAMENTO.md`, "Conferir pela API") | Ok: `prometheus`, `cadvisor`, `nginx` e `mysql`, com `mysql_up` e `nginx_up` em 1 (de novo na rodada do Laravel 13.40.0, com o `exporter` criado pelo script de init no banco novo) |
| A7 | Logs centralizados | Os serviços com log no Loki, pela API do Grafana | Ok: os 13 serviços do projeto; o erro de banco chegou sem SQL e sem dado pessoal. Na rodada do Laravel 13.40.0, os 13 serviços, a senha do root do banco novo com `[oculto]` e a falha da rodada do lembrete (os 8 avisos e o `ERROR`), sem dado pessoal |
| A8 | Painel com dados | `GET /api/dashboards/uid/barberflow` no Grafana: 13 itens (10 painéis e 3 linhas) | Ok (de novo na rodada do Laravel 13.40.0, com o admin entrando com a senha do `homolog.env` e `admin`/`admin` com 401) |
| A9 | Teste de carga com as metas (p95 abaixo de 500 ms e menos de 1% de erro): smoke, carga com 50 VUs e estresse com 200 | `load-tests/README.md` | Ok: p95 de 15,5 ms, 16,8 ms e 17,6 ms, todos com 0% de erro. Na rodada do Laravel 13.40.0, só o smoke: p95 de 16,1 ms e 0% de erro |
| A10 | Concorrência da agenda: só 1 de N pedidos simultâneos passa | `load-tests/README.md`, "Concorrência da agenda" | Ok: 1 criado em 10 pedidos e 1 em 50, o resto com 422; na rodada do Laravel 13.40.0, 1 em 10 |
| A11 | Resiliência: banco, app e cache caindo e voltando | `load-tests/resiliencia/README.md` | Ok: o `/api/health` em 503 (cache em 3 a 4 s, banco em 13,3 s) e de volta em 200; app de volta em 1,7 s. Com o banco num volume de verdade, o `start` bastou: `/api/health` 200 em 1,9 s, com os dados e o token de antes, sem refazer as migrations nem os seeders. Achado da medição: com o banco congelado, a requisição ficava presa até o 504 do Nginx, em 60 s; depois dela entrou o limite de leitura do MySQL (`DB_READ_TIMEOUT`, padrão 5 s). Com ele, na rodada de acabamento: `/api/health` em 503 em 20,05 s, tela e API em 500 em 20,03 s e o PHP-FPM livre com o banco ainda congelado; com 10 pedidos ao mesmo tempo, o pool encheu e o `/up` esperou 16,79 s. Servidor de e-mail fora: cadastro em 201 com o aviso e reenvio do link em 503, sem endereço no log; congelado, o reenvio esperou 60,03 s, porque o SMTP não tinha limite de tempo. Com o `MAIL_TIMEOUT` da segunda rodada: reenvio em 503 em 10,04 s, cadastro em 201 em 10,42 s, "Enviar lembrete agora" em 503 em 10,06 s e a rodada do lembrete com 10 s por lembrete, com a falha no log do `scheduler` (só o id e as contas) e os lembretes enviados na rodada seguinte. O pool cheio ficou nos achados em aberto (`docs/RELATORIO-TECNICO.md`, seção 8). Na rodada do Laravel 13.40.0, só a rodada do lembrete com o Mailpit congelado: 8 lembretes em 1 min 20 s, a falha no log do `scheduler` e no Loki e os 8 enviados na rodada seguinte |
| A12 | Portas internas protegidas | `docker ps` mostra só a 8080, a 8025, a 3000 e a 9090, todas no 127.0.0.1; `/nginx_status` na 8080 responde 404 | Ok: as portas e o 404 do `/nginx_status` conferidos de novo na rodada do Laravel 13.40.0 (15/11/2026) |
| A13 | Headers e informação de versão | `curl.exe -I http://localhost:8080/login`: sem `X-Powered-By`, `Server: nginx` sem versão, e os headers de segurança da aplicação | Ok: os 6 headers em telas, API, erros e `/up`; sem HSTS em HTTP (de novo na rodada do Laravel 13.40.0). O OWASP ZAP baseline deu 0 FAIL (`docs/nuvem/SEGURANCA.md`) |
| A14 | Nenhuma senha nova no código | Busca por `password`, `senha` e `secret` nos arquivos novos | Ok: as senhas novas (homolog, Grafana, exporter, admin e demonstração) ficam no `homolog.env`, que o Git ignora, ou só no ambiente do comando. As que já existiam ficam como estão (decisão 5) e estão nos riscos aceitos do `docs/nuvem/SEGURANCA.md` |
| A15 | Subir de novo sem perder nada | `homolog down` (sem `-v`) e `homolog up -d --wait`: login e dados continuam | Ok, no volume padrão, na rodada do Laravel 13.40.0: `homologmon down` (sem `-v`) em 5,9 s e `up` em 14,4 s, com "Nothing to migrate", o mesmo volume, os mesmos dados e o token da API e a sessão do navegador de antes do `down` valendo. Antes, na revisão final, num volume novo (`barberflow-homolog_dbdata-r`, apagado depois): `down` em 2,6 s, com os volumes mantidos, e `up` em 11,8 s, com "Nothing to migrate"; o `/api/health` em 200, os mesmos 177 agendamentos e 95 pagamentos do `DemoSeeder`, o token da API de antes do `down` e a sessão do navegador valendo, e o login de novo pela API em 200 |
| A16 | Terraform válido e testado | `tf fmt -check -recursive`, `tf validate`, `tf test` e `trivy config` (`docs/nuvem/TERRAFORM.md`) | Ok: 69 testes na raiz e 1 no bootstrap, `trivy config` sem alerta, actionlint sem aviso |

## B. AWS

Tudo desta parte precisa da conta do grupo e fica para depois do primeiro `apply` (`docs/nuvem/TERRAFORM.md`, "Primeiro deploy").

| # | Item | Como conferir |
|---|---|---|
| B1 | Instâncias saudáveis no target group | `aws elbv2 describe-target-health` (`TERRAFORM.md`, "Primeiro deploy", passo 5) |
| B2 | O `/api/health` responde 200 pelo ALB | `curl.exe -i <APP_URL>/api/health` |
| B3 | HTTPS, com domínio e certificado | `curl.exe -I https://<domínio>/login`; a 80 redireciona (301) para a 443 (`TERRAFORM.md`, "HTTPS com domínio próprio") |
| B4 | E-mail dos alarmes confirmado e testado | A assinatura fora de `PendingConfirmation` e um alarme posto em `ALARM` à mão, com o e-mail chegando (`TERRAFORM.md`, "Observabilidade") |
| B5 | Aviso do orçamento | O `budget_alert_email` preenchido e o orçamento no console do Billing (`CUSTOS.md`) |
| B6 | Dashboard com dados | A saída `cloudwatch_dashboard_url`, com tráfego no ALB |
| B7 | Logs no CloudWatch | `aws logs tail /barberflow/<ambiente>/app` e `/web` (`TERRAFORM.md`, "Logs no CloudWatch"); a rodada do lembrete escreve no stream do `scheduler`, no grupo `app` |
| B8 | Restore testado | O teste de restore do `docs/nuvem/BACKUP.md`, com o tempo anotado |
| B9 | Administrador criado | `AdminUserSeeder` pelo Session Manager (`TERRAFORM.md`, "Criar o administrador"), nunca o `DatabaseSeeder` |
| B10 | Deploy e rollback | Uma tag `v*` e um rollback pelo `image_tag` (`docs/nuvem/CI-CD.md`) |
| B11 | Acesso sem SSH | `aws ssm start-session` entra; nenhuma porta 22 nos security groups |
| B12 | Teste de carga na homolog da AWS (opcional) | `load-tests/README.md`, "Na AWS" |

## Não verificado

- Mac e Linux: a homolog e o monitoramento foram conferidos só no Windows 11, com o Docker Desktop.
- Qualquer item da parte B: nada foi criado na AWS.
- Com o Laravel 13.40.0: a carga e o estresse do k6 (A9), a concorrência com 50 pedidos (A10), as quedas do banco, do app e do cache (A11), o ZAP (A13), as senhas no código (A14) e o Terraform (A16) não rodaram de novo e valem os resultados da 13.33.0. A atualização mudou só o `composer.lock` e um comentário de teste, e o smoke e a concorrência com 10 pedidos, que rodaram, passaram nas metas, como antes.
- A queda do app e o cache fora (`stop redis`) com o banco num volume: os dois casos não dependem de onde o MySQL guarda os dados, e valem os números do tmpfs. O cache congelado foi medido de novo com o volume, na rodada de acabamento, com os mesmos números.
- O HSTS ao vivo: a homolog não tem TLS. Ele fica provado pelos testes automáticos e volta a ser conferido no B3.
