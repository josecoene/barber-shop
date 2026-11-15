# Checklist de go-live

Este checklist diz o que conferir antes de abrir o BarberFlow para clientes de verdade. Ele parte do checklist da Sprint 4 (12 itens, executados em 29/10/2026 na stack local daquela época; o original está no relatório, `docs/nuvem/sprint4/relatorio.html`) e foi adaptado para a stack de agora: a homolog com as imagens de produção, o monitoramento, os testes de carga com login, a resiliência e a AWS.

São duas partes:

- **A. Homologação local**, antes de aplicar o Terraform: a homolog roda as mesmas imagens que vão para a AWS (`docs/nuvem/DOCKER.md`).
- **B. AWS**, depois do primeiro `apply`, com a conta do grupo.

A coluna "Situação" diz como cada item estava em 15/11/2026, na entrega final (`feat/entrega-final`), que juntou a trilha da aplicação (login, `/api/health`, agenda e pagamentos) à de nuvem e rodou a verificação completa. Os números estão no `docs/RELATORIO-TECNICO.md`, seção "Evidências da verificação". Em todos os itens menos o A15, o MySQL da homolog ficou num tmpfs, porque o volume `barberflow-homolog_dbdata` deste computador tem uma senha que se perdeu. O A15 e a queda do banco com o volume (no A11) rodaram depois, na revisão final do mesmo dia, com o MySQL num volume novo (`barberflow-homolog_dbdata-r`); o volume antigo ficou intocado. Por último, na rodada de acabamento, também em 15/11, a homolog subiu de novo nesse volume, com as imagens refeitas, e as quedas do banco e do cache e o servidor de e-mail fora do ar foram medidos outra vez (A4, A5 e A11). Na segunda rodada de acabamento, ainda em 15/11, ela subiu mais uma vez, do mesmo jeito, para medir o servidor de e-mail congelado com o limite de tempo novo (`MAIL_TIMEOUT`) e a falha do lembrete no log do agendador (A5 e A11).

## A. Homologação local

| # | Item | Como conferir | Situação em 15/11/2026 |
|---|---|---|---|
| A1 | A stack sobe do zero | Banco novo, `homolog up -d --build --wait` (ou `homologmon`, com o monitoramento) | Ok: com o monitoramento, os 13 containers no ar em 18,2 s, com as migrations rodando na subida |
| A2 | O `/up` responde 200 | `curl.exe -I http://localhost:8080/up` | Ok |
| A3 | O `/api/health` responde 200, com banco e cache | `curl.exe -i http://localhost:8080/api/health` | Ok: `{"status":"ok","checks":{"database":"ok","cache":"ok"}}`, em 9 ms |
| A4 | Login e telas de cada perfil (admin, barbeiro e cliente) | Navegador em http://localhost:8080/login, com o `DemoSeeder` (`docs/nuvem/DOCKER.md`, "Dados de demonstração e administrador") | Ok: o admin abriu as 8 telas com a API em 200 e o console vazio, com a CSP ligada (e 7 delas de novo na rodada de acabamento, com as imagens refeitas); os 44 prints das telas dos 3 perfis e do visitante estão em `docs/prints-telas/`, 22 deles refeitos na rodada de acabamento |
| A5 | Suíte de testes da aplicação | `composer test` e `composer test:mysql` (CI, jobs `PHP` e `MySQL`) | Ok: 745 passaram e 9 pulados no SQLite (os de trava, que só valem no MySQL) e 754 no MySQL 8.4, nenhum pulado (depois da segunda rodada de acabamento); a rodada igual à do CI, numa cópia sem `vendor` e com o `.env` do `.env.example`, também passou, com os mesmos 745 |
| A6 | Coleta de métricas | Os 4 alvos `UP` no Prometheus (`docs/nuvem/MONITORAMENTO.md`, "Conferir pela API") | Ok: `prometheus`, `cadvisor`, `nginx` e `mysql`, com `mysql_up` e `nginx_up` em 1 |
| A7 | Logs centralizados | Os serviços com log no Loki, pela API do Grafana | Ok: os 13 serviços do projeto; o erro de banco chegou sem SQL e sem dado pessoal |
| A8 | Painel com dados | `GET /api/dashboards/uid/barberflow` no Grafana: 13 itens (10 painéis e 3 linhas) | Ok |
| A9 | Teste de carga com as metas (p95 abaixo de 500 ms e menos de 1% de erro): smoke, carga com 50 VUs e estresse com 200 | `load-tests/README.md` | Ok: p95 de 15,5 ms, 16,8 ms e 17,6 ms, todos com 0% de erro |
| A10 | Concorrência da agenda: só 1 de N pedidos simultâneos passa | `load-tests/README.md`, "Concorrência da agenda" | Ok: 1 criado em 10 pedidos e 1 em 50, o resto com 422 |
| A11 | Resiliência: banco, app e cache caindo e voltando | `load-tests/resiliencia/README.md` | Ok: o `/api/health` em 503 (cache em 3 a 4 s, banco em 13,3 s) e de volta em 200; app de volta em 1,7 s. Com o banco num volume de verdade, o `start` bastou: `/api/health` 200 em 1,9 s, com os dados e o token de antes, sem refazer as migrations nem os seeders. Achado da medição: com o banco congelado, a requisição ficava presa até o 504 do Nginx, em 60 s; depois dela entrou o limite de leitura do MySQL (`DB_READ_TIMEOUT`, padrão 5 s). Com ele, na rodada de acabamento: `/api/health` em 503 em 20,05 s, tela e API em 500 em 20,03 s e o PHP-FPM livre com o banco ainda congelado; com 10 pedidos ao mesmo tempo, o pool encheu e o `/up` esperou 16,79 s. Servidor de e-mail fora: cadastro em 201 com o aviso e reenvio do link em 503, sem endereço no log; congelado, o reenvio esperou 60,03 s, porque o SMTP não tinha limite de tempo. Com o `MAIL_TIMEOUT` da segunda rodada: reenvio em 503 em 10,04 s, cadastro em 201 em 10,42 s, "Enviar lembrete agora" em 503 em 10,06 s e a rodada do lembrete com 10 s por lembrete, com a falha no log do `scheduler` (só o id e as contas) e os lembretes enviados na rodada seguinte. O pool cheio ficou nos achados em aberto (`docs/RELATORIO-TECNICO.md`, seção 8) |
| A12 | Portas internas protegidas | `docker ps` mostra só a 8080, a 8025, a 3000 e a 9090, todas no 127.0.0.1; `/nginx_status` na 8080 responde 404 | Ok: as portas conferidas em 15/11/2026; o 404 do `/nginx_status`, em 14/11/2026 (o Nginx não mudou depois) |
| A13 | Headers e informação de versão | `curl.exe -I http://localhost:8080/login`: sem `X-Powered-By`, `Server: nginx` sem versão, e os headers de segurança da aplicação | Ok: os 6 headers em telas, API, erros e `/up`; sem HSTS em HTTP. O OWASP ZAP baseline deu 0 FAIL (`docs/nuvem/SEGURANCA.md`) |
| A14 | Nenhuma senha nova no código | Busca por `password`, `senha` e `secret` nos arquivos novos | Ok: as senhas novas (homolog, Grafana, exporter, admin e demonstração) ficam no `homolog.env`, que o Git ignora, ou só no ambiente do comando. As que já existiam ficam como estão (decisão 5) e estão nos riscos aceitos do `docs/nuvem/SEGURANCA.md` |
| A15 | Subir de novo sem perder nada | `homolog down` (sem `-v`) e `homolog up -d --wait`: login e dados continuam | Ok, com o MySQL num volume novo (`barberflow-homolog_dbdata-r`): `down` em 2,6 s, com os volumes mantidos, e `up` em 11,8 s, com "Nothing to migrate"; o `/api/health` em 200, os mesmos 177 agendamentos e 95 pagamentos do `DemoSeeder`, o token da API de antes do `down` e a sessão do navegador valendo, e o login de novo pela API em 200 |
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
- O volume `barberflow-homolog_dbdata`, com a senha perdida, ficou sem uso: o A15 e a queda do banco usaram um volume novo. Apagar ou trocar o antigo (e apagar o novo, se ele não for mais usado) espera o OK do Leonam.
- A queda do app e o cache fora (`stop redis`) com o banco num volume: os dois casos não dependem de onde o MySQL guarda os dados, e valem os números do tmpfs. O cache congelado foi medido de novo com o volume, na rodada de acabamento, com os mesmos números.
- O HSTS ao vivo: a homolog não tem TLS. Ele fica provado pelos testes automáticos e volta a ser conferido no B3.
