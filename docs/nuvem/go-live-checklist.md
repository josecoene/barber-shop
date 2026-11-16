# Checklist de go-live

Este checklist diz o que conferir antes de abrir o BarberFlow para clientes de verdade. Ele parte do checklist da Sprint 4 (12 itens, executados em 29/10/2026 na stack local daquela época; o original está no relatório, `docs/nuvem/sprint4/relatorio.html`) e foi adaptado para a stack de agora: a homolog com as imagens de produção, o monitoramento, os testes de carga com login, a resiliência e a AWS.

São duas partes:

- **A. Homologação local**, antes de aplicar o Terraform: a homolog roda as mesmas imagens que vão para a AWS (`docs/nuvem/DOCKER.md`).
- **B. AWS**, depois do primeiro `apply`, com a conta do grupo.

A coluna "Situação" diz como cada item estava em 14/11/2026, na branch `feat/observabilidade`. Essa branch saiu da `fix/menu-e-setup` e não tem o código da aplicação das branches `feat/api-qualidade` a `feat/lembretes-fidelidade` (login, `/api/health`, agenda e pagamentos): o que depende disso está marcado **verificado na F**, a entrega final (`feat/entrega-final`), que junta as duas trilhas e roda a verificação completa.

## A. Homologação local

| # | Item | Como conferir | Situação em 14/11/2026 |
|---|---|---|---|
| A1 | A stack sobe do zero | Banco novo, `homolog up -d --build --wait` (ou `homologmon`, com o monitoramento) | Ok: com o monitoramento, os 13 containers no ar em 20 s, com o MySQL num tmpfs (o volume da homolog deste computador tem uma senha perdida) |
| A2 | O `/up` responde 200 | `curl.exe -I http://localhost:8080/up` | Ok |
| A3 | O `/api/health` responde 200, com banco e cache | `curl.exe -i http://localhost:8080/api/health` | Verificado na F (a rota chega com a `feat/api-qualidade`) |
| A4 | Login e telas de cada perfil (admin, barbeiro e cliente) | Navegador em http://localhost:8080/login, com o `DemoSeeder` | Verificado na F |
| A5 | Suíte de testes da aplicação | `composer test` e `composer test:mysql` (CI, jobs `PHP` e `MySQL`) | Verificado na F |
| A6 | Coleta de métricas | Os 4 alvos `UP` no Prometheus (`docs/nuvem/MONITORAMENTO.md`, "Conferir pela API") | Ok: `prometheus`, `cadvisor`, `nginx` e `mysql`, com `mysql_up` e `nginx_up` em 1 |
| A7 | Logs centralizados | Os serviços com log no Loki, pela API do Grafana | Ok: os 13 serviços do projeto; o e-mail de uma busca não chegou ao log |
| A8 | Painel com dados | `GET /api/dashboards/uid/barberflow` no Grafana: 13 painéis | Ok |
| A9 | Teste de carga com as metas (p95 abaixo de 500 ms e menos de 1% de erro): smoke, carga com 50 VUs e estresse com 200 | `load-tests/README.md` | Verificado na F (o login chega com a `feat/auth`). Os scripts passaram no `k6 inspect` e rodaram contra uma API falsa com o mesmo contrato: um token por VU (200 no estresse), logout de todos e o JSON do resultado sem tokens |
| A10 | Concorrência da agenda: só 1 de N pedidos simultâneos passa | `load-tests/README.md`, "Concorrência da agenda" | Verificado na F (a agenda chega com a `feat/agendamentos`). Contra a API falsa, o script passou com a trava e acusou o defeito sem ela (20 pedidos criados) |
| A11 | Resiliência: banco, app e cache caindo e voltando | `load-tests/resiliencia/README.md` | Parcial: app (504 em 2 s parado, volta em 1,7 s), banco e cache medidos pelas telas e pelo `/up`; o `/api/health` em 503 e depois 200 fica para a F |
| A12 | Portas internas protegidas | `docker ps` mostra só a 8080, a 8025, a 3000 e a 9090, todas no 127.0.0.1; `/nginx_status` na 8080 responde 404 | Ok |
| A13 | Headers e informação de versão | `curl.exe -I http://localhost:8080/login`: sem `X-Powered-By`, `Server: nginx` sem versão, e os headers de segurança da aplicação | Ok no Nginx; os headers da aplicação são verificados na F (chegam com a `feat/auth`) |
| A14 | Nenhuma senha nova no código | Busca por `password`, `senha` e `secret` nos arquivos novos | Ok: as senhas novas (homolog, Grafana e exporter) ficam no `homolog.env`, que o Git ignora. As que já existiam ficam como estão (decisão 5) e estão nos riscos aceitos do `docs/nuvem/SEGURANCA.md` |
| A15 | Subir de novo sem perder nada | `homolog down` (sem `-v`) e `homolog up -d --wait`: login e dados continuam | Verificado na F, com o volume novo do banco da homolog (nesta branch, o banco ficou num tmpfs) |
| A16 | Terraform válido e testado | `tf fmt -check -recursive`, `tf validate`, `tf test` e `trivy config` (`docs/nuvem/TERRAFORM.md`) | Ok: 69 testes na raiz e 1 no bootstrap, `trivy config` sem alerta |

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
| B7 | Logs no CloudWatch | `aws logs tail /barberflow/<ambiente>/app` e `/web` (`TERRAFORM.md`, "Logs no CloudWatch") |
| B8 | Restore testado | O teste de restore do `docs/nuvem/BACKUP.md`, com o tempo anotado |
| B9 | Administrador criado | `AdminUserSeeder` pelo Session Manager (`TERRAFORM.md`, "Criar o administrador"), nunca o `DatabaseSeeder` |
| B10 | Deploy e rollback | Uma tag `v*` e um rollback pelo `image_tag` (`docs/nuvem/CI-CD.md`) |
| B11 | Acesso sem SSH | `aws ssm start-session` entra; nenhuma porta 22 nos security groups |
| B12 | Teste de carga na homolog da AWS (opcional) | `load-tests/README.md`, "Na AWS" |

## Não verificado

- Mac e Linux: a homolog e o monitoramento foram conferidos só no Windows 11, com o Docker Desktop.
- Qualquer item da parte B: nada foi criado na AWS.
