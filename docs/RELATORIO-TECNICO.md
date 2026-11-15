# Relatório técnico do BarberFlow

Relatório final do projeto, para as duas disciplinas: **Arquitetura de Microsserviços e Desenvolvimento de APIs** e **Computação em Nuvem**. Ele junta a API, as 4 sprints de nuvem do `Sistema Cloud.pdf`, as evidências da verificação da entrega final e a situação de cada falta da auditoria de 12/11/2026.

- **Equipe:** Eduardo Romano, Jose Coene, Leonam e Manoel.
- **Situação em 15/11/2026:** tudo no código, na branch `feat/entrega-final`, com a aplicação e a nuvem juntas. Nada foi aplicado na AWS (precisa da conta do grupo e custa dinheiro): a nuvem está validada no código e ensaiada numa homolog local com as mesmas imagens de produção.
- **De onde vêm os números:** da verificação feita entre 23h30 de 14/11 e 0h30 de 15/11/2026 (horário de Brasília), dos prints de 15/11 e, no mesmo dia, da revisão final, da rodada de acabamento (seção 4.10) e da segunda rodada de acabamento (seção 4.11). Os registros brutos dela (logs e scripts) e a lista da auditoria ficaram no computador do Leonam, fora do repositório. No repositório ficam os resumos do k6 (`load-tests/results/f1-*.json`), os prints (`docs/prints-telas/`) e este relatório.

## Sumário

1. [O projeto](#1-o-projeto)
2. [A API](#2-a-api)
3. [A nuvem: as 4 sprints do Sistema Cloud.pdf](#3-a-nuvem-as-4-sprints-do-sistema-cloudpdf)
4. [Evidências da verificação](#4-evidências-da-verificação)
5. [Faltas da auditoria de 12/11/2026](#5-faltas-da-auditoria-de-12112026)
6. [Fora do escopo](#6-fora-do-escopo)
7. [Pendências](#7-pendências)
8. [Achados que ficaram registrados](#8-achados-que-ficaram-registrados)

## 1. O projeto

O BarberFlow é um sistema de gestão e agendamento para barbearias. A barbearia (admin e barbeiros) cadastra serviços, barbeiros e clientes, monta a agenda, registra os pagamentos e acompanha o faturamento. O cliente cria a conta, agenda sozinho e acompanha a fidelidade.

A arquitetura é um **monólito modular**: um serviço Laravel só, com as regras de cada módulo numa classe de `app/Services`, rodando em três containers (`web`, `app` e `scheduler`). O `Sistema Cloud.pdf` fala em "microsserviços conteinerizados"; o porquê da escolha e quando separar um serviço estão no [ADR 0001](adr/0001-monolito-modular.md).

### Como o trabalho foi dividido

O Leonam pediu, em 12/11/2026, para deixar o projeto completo nas duas réguas. A auditoria do mesmo dia achou cerca de 95 faltas (seção 5). O trabalho saiu em 13 branches locais, com commits locais e sem push (decisão 4 do plano). As partes dos colegas ficaram numa branch por dono, para cada um revisar a sua (decisão 3 do plano, 12/11/2026):

| Branch | Dono (pelo PDF) | O que entregou |
|---|---|---|
| A1 `fix/menu-e-setup` | grupo | Menu que abria o JSON da API, setup, PHP 8.4, seeders repetíveis, Pint e alertas do Symfony |
| A2 `feat/api-qualidade` | grupo | Paginação, busca, exclusão lógica com anonimização, pt-BR, fuso de São Paulo e `/api/health` |
| A3 `feat/auth` | Leonam (segurança) | Login por sessão e por token, perfis, permissões, conta de cliente, limites e headers |
| A4 `feat/agendamentos` | grupo | Agenda, horários livres, status e as telas da agenda |
| A5 `feat/pagamentos-dashboard` | grupo | Pagamentos, dashboard e as telas |
| A6 `feat/lembretes-fidelidade` | grupo | Lembrete por e-mail e fidelidade |
| N1 `feat/infra-docker` | José | Imagem, compose e Nginx |
| N2 `feat/infra-rede` | Manoel | Versões, state remoto, VPC e subnets |
| N3 `feat/infra-dados-seguranca` | Eduardo | Security groups, IAM, ECR, logs, RDS, cache e parâmetros |
| N4 `feat/infra-computacao` | Manoel | ALB, Auto Scaling, `user_data` e orçamento |
| N5 `feat/ci-cd` | José (CI) e Manoel (CD) | Pipelines, OIDC e Dependabot |
| N6 `feat/observabilidade` | Leonam | Monitoramento, carga, resiliência e os docs de nuvem |
| F `feat/entrega-final` | Leonam (docs) | O merge das duas trilhas, o `DemoSeeder`, os docs finais e a verificação completa |

As decisões que valem para todo o projeto:

- **O cliente tem conta e agenda sozinho** (decisão revista pelo Leonam em 13/11/2026). Admin e barbeiro continuam agendando para quem chega no balcão.
- **Nenhuma senha que já existe muda** (decisão 5, 13/11/2026): a do MySQL de dev no `docker-compose.yml`, a do RDS no `terraform/main.tf` e a do `test@example.com` no `DatabaseSeeder`. O histórico do Git também não é reescrito. O risco está no `docs/nuvem/SEGURANCA.md`, "Riscos aceitos".
- **Lembretes e fidelidade entram**, em versão simples (decisão 1, 12/11/2026).

## 2. A API

### Recursos

| Recurso | Rotas (`/api`) | Regras principais | Testes |
|---|---|---|---|
| Saúde | `GET /health` | Confere banco e cache: 200 ou 503, fora do limite de requisições. O `/up` continua raso, para o ALB | `HealthApiTest` |
| Conta e acesso | `POST /login`, `/logout`, `/register`, `/forgot-password`, `/reset-password` e `/email/verification-notification`; `GET /user`; `PUT /user/password` | Token Bearer com validade de 480 minutos e prefixo `barberflow_`; conta de cliente ligada ao cadastro só depois de confirmar o e-mail | `AuthApiTest`, `RegistrationTest`, `PasswordResetTest` e `EmailVerificationConcurrencyTest` |
| Serviços | `apiResource('services')` | Ativo ou inativo, limites iguais aos do banco, exclusão lógica | `ServiceApiTest` |
| Clientes | `apiResource('clients')` e `GET /clients/{id}/loyalty` | Excluir anonimiza (LGPD); o cliente vê só o próprio cadastro e não muda o e-mail | `ClientApiTest` e `LoyaltyApiTest` |
| Barbeiros | `apiResource('barbers')` e `GET /barbers/{id}/available-slots` | O campo `password` cria o login; o cliente vê só nome e id | `BarberApiTest` e `AvailableSlotsTest` |
| Agenda | `apiResource('appointments')` sem `destroy`, `PATCH /appointments/{id}/status` e `POST /appointments/{id}/reminder` | Término e preço do servidor, expediente, sem sobreposição por barbeiro e por cliente, limites, transições e o lembrete manual | `AppointmentApiTest`, `AppointmentAuthorizationTest`, `AppointmentConcurrencyTest` e `ReminderApiTest` |
| Pagamentos | `apiResource('payments')` sem `destroy` e `PATCH /payments/{id}/status` | Um por atendimento concluído, valor padrão do agendamento, estorno só pelo admin e o prêmio da fidelidade | `PaymentApiTest`, `PaymentAuthorizationTest`, `PaymentConcurrencyTest` e `LoyaltyConcurrencyTest` |
| Dashboard | `GET /dashboard` | Só o admin; faturamento, atendimentos, ticket médio, faltas, ocupação, top serviços e barbeiros, num período de até 366 dias | `DashboardApiTest` |

A referência completa, com os corpos, os códigos e os exemplos, está no [`docs/API.md`](API.md), e a coleção do Postman em `docs/BarberFlow.postman_collection.json`.

### Regras

As regras de cada módulo ficam numa classe só, e os status e as transições num enum. O detalhe de cada uma está no [`docs/ARQUITETURA.md`](ARQUITETURA.md).

- **Agenda** (`App\Services\AppointmentScheduler`): o término sai do serviço; só dentro do expediente (09:00 às 19:00, de segunda a sábado, em fatias de 30 minutos, tudo na config); nada no passado nem depois de 60 dias; sem sobreposição `[início, fim)` por barbeiro e por cliente; o cliente que agenda pela conta tem no máximo 3 horários ativos e cancela até 2 horas antes. Agendar trava as linhas na ordem barbeiro, cliente e serviço, e as consultas sem trava vêm depois de todas as travas.
- **Pagamentos** (`App\Services\PaymentRecorder`): só atendimento concluído, um pagamento por atendimento para sempre, `paid_at` do servidor, as transições pendente, pago e estornado, e o `unique` de `appointment_id` como última barreira (a violação vira 422, nunca 500).
- **Fidelidade** (`App\Services\LoyaltyService`): a cada 10 atendimentos concluídos, o seguinte sai com 100% de desconto (os dois números na config), com o desconto calculado em centavos e o estorno devolvendo o prêmio.
- **Lembretes** (`App\Services\ReminderSender`): e-mail 24 horas antes, pela rodada do agendador a cada 15 minutos, com a reserva da linha antes de enviar (um lembrete só, mesmo com duas rodadas juntas), sem dado pessoal no e-mail nem no log e com a falha no log do próprio agendador.
- **Dashboard** (`App\Services\DashboardMetrics`): tudo pela data do atendimento, com consultas agregadas que rodam iguais no SQLite e no MySQL.

### Segurança

- **Login:** sessão nas telas (cookie e CSRF, `statefulApi()`), token Bearer na API. Limite de senhas erradas por e-mail e IP, sessão com id novo no login e sessões derrubadas quando a senha muda.
- **Permissões:** uma Policy por recurso, com o admin liberado no `before()`, e escopos por perfil nas listagens (`visibleTo`). Testes de 401 em todas as rotas e de 403 para cada perfil sem permissão.
- **Requisições:** limites por minuto, headers de segurança e CSP sem nada inline, CORS restrito ao `APP_URL`, hosts aceitos e links dos e-mails sempre pelo `APP_URL`.
- **LGPD:** cliente excluído anonimizado, lembrete sem dado pessoal, erro de banco no log sem o SQL e falha no envio de e-mail no log sem o endereço.

O checklist OWASP API Security Top 10, com o arquivo:linha de cada controle, o teste que prova e o achado do ZAP, está no [`docs/nuvem/SEGURANCA.md`](nuvem/SEGURANCA.md).

### Testes

- **519 métodos de teste em 37 arquivos** (`tests/Feature` e `tests/Unit`). Com os conjuntos de dados (data providers), são 754 casos.
- **No SQLite** (`composer test`), 745 passam e 9 aparecem como pulados: os de trava, que só valem no MySQL.
- **No MySQL 8.4** (`composer test:mysql`), os 754 passam, com as travas da agenda, do pagamento, da fidelidade e da confirmação do e-mail conferidas com duas conexões abertas ao mesmo tempo.
- **Com pedidos simultâneos de verdade**, o `load-tests/concorrencia.js` (k6) confere a trava da agenda.

O que cada arquivo confere está no [`README.md`](../README.md), "O que os testes cobrem".

## 3. A nuvem: as 4 sprints do Sistema Cloud.pdf

O `Sistema Cloud.pdf` (na raiz do repositório, lido com `pdftotext -layout`) divide o projeto em 4 fases, com uma sprint cada, e dá um papel a cada pessoa:

| Pessoa | Papel no PDF | Branches |
|---|---|---|
| Manoel | Lead Cloud & DevOps: arquitetura geral da nuvem, Terraform e orquestração | N2, N4 e o CD da N5 |
| Eduardo | Infrastructure & Database: banco gerenciado e segurança de acesso (IAM e security groups) | N3 |
| José | Containers & Automation: Dockerfile, `docker-compose.yml` e Nginx | N1 e o CI da N5 |
| Leonam | Security, Monitoring & QA: diretrizes de segurança, resiliência, métricas e documentação técnica | N6, a segurança da A3 e a F |

Em cada sprint: o que o PDF pede, onde está no repositório e como foi provado. "Validado só no código" quer dizer `terraform validate`, `terraform test` com o provider simulado, `trivy config` e actionlint, sem a AWS.

### Sprint 1: Infraestrutura como Código e containerização

**O que o PDF pede.** Fase 1: "planejamento da topologia de rede em nuvem (VPC, subnets e segurança)", "criação dos arquivos de Infraestrutura como Código (Terraform) e scripts de provisionamento" e "configuração do ambiente base de containers (Docker e Docker-Compose)". Entregas da sprint: "arquivos .tf do Terraform, estrutura de containers (docker/), orquestração (docker-compose.yml) e configurações do Nginx". O PDF dava a Sprint 1 como entregue, mas a auditoria de 12/11 achou o compose montando uma pasta que não existia, o PHP 8.2 com o projeto exigindo 8.4 e o Terraform sem rede.

| Entrega | Onde está | Branch |
|---|---|---|
| Imagem em estágios (`base`, `dev`, `vendor`, `assets`, `web` e `app`), PHP-FPM 8.4.27 sem root e sem compilador, Nginx 1.30.6 | `docker/php/Dockerfile`, `docker/php/conf.d/`, `docker/php/php-fpm.d/zz-barberflow.conf` e `docker/nginx/default.conf` | N1 |
| Compose de dev (MySQL só no 127.0.0.1, Redis, app, agendador, Nginx, healthchecks e redes separadas), de produção e de homologação | `docker-compose.yml`, `docker-compose.prod.yml` e `docker-compose.homolog.yml` | N1 |
| Versões fixas do Terraform (1.11 ou mais) e do provider AWS (6.73.0 no lock) e o state remoto no S3 com trava | `terraform/versions.tf`, `terraform/.terraform.lock.hcl`, `terraform/backend.tf` e `terraform/bootstrap/` | N2 |
| VPC por ambiente, 2 subnets públicas e 2 privadas em zonas diferentes, internet gateway e as privadas sem saída | `terraform/network.tf`, `variables.tf`, `outputs.tf` e `envs/*.tfvars.example` | N2 |

**Como foi provado:** a homolog subiu com as imagens de produção (seção 4.3); `terraform test` da rede com 8 testes. **Guias:** `docs/nuvem/DOCKER.md` e `docs/nuvem/TERRAFORM.md`.

### Sprint 2: camada de dados e serviços gerenciados

**O que o PDF pede.** Fase 2: "implementação de bancos de dados relacionais gerenciados (RDS) e camadas de cache distribuído" e "configuração de políticas de backup automatizado e segurança de dados". Entregas: "provisionamento do banco relacional gerenciado e conexões de rede seguras".

| Entrega | Onde está | Branch |
|---|---|---|
| RDS MySQL 8.4 privado, criptografado, com TLS obrigatório (`require_secure_transport` e o bundle de CA na imagem), backup de 7 dias, snapshot final, proteção contra exclusão e Multi-AZ por variável | `terraform/main.tf` | N3 |
| ElastiCache Valkey 9.1 com TLS e criptografia, réplica com failover na prod | `terraform/cache.tf` | N3 |
| Security groups em camadas: ALB da internet, app só do ALB, banco e cache só do app | `terraform/sg.tf` | N3 |
| Role das instâncias com o mínimo (Session Manager, ECR, logs e os parâmetros do próprio ambiente), ECR e log groups | `terraform/iam.tf`, `terraform/ecr.tf` e `terraform/logs.tf` | N3 |
| Parâmetros do SSM que viram o `.env` das instâncias, com a senha e o `APP_KEY` como SecureString | `terraform/ssm.tf` | N3 |
| Restore documentado, com RPO de cerca de 5 minutos e RTO estimado de 45 a 60 minutos | `docs/nuvem/BACKUP.md` | N3 |

**Como foi provado:** validado só no código, com 19 testes de dados e segurança e o `trivy config` sem nenhum problema (seção 4.8). **Só a AWS prova:** o TLS do RDS e do ElastiCache, o IAM mínimo e o teste de restore (`docs/nuvem/go-live-checklist.md`, B8).

### Sprint 3: automação, containerização avançada e CI/CD

**O que o PDF pede.** Fase 3: "otimização das imagens de containers e padronização do ambiente" e "configuração de pipelines de Integração e Entrega Contínua (CI/CD) para deploys automatizados". Entregas: "pipelines de build e deploy contínuo configurados".

| Entrega | Onde está | Branch |
|---|---|---|
| Imagem de produção otimizada: `php.ini-production`, OPcache sem conferir a data dos arquivos, `optimize` na subida, sem compilador (de 311 para 115 alertas altos e críticos no Trivy, nenhum crítico com correção) | `docker/php/Dockerfile` e `docker/php/entrypoint.sh` | N1 e N5 |
| ALB com health check no `/up` e HTTPS com o certificado do ACM quando houver domínio; Auto Scaling em 2 zonas, IMDSv2, disco criptografado e escala pela CPU; `user_data` que monta o `.env` pelo SSM | `terraform/alb.tf`, `terraform/compute.tf` e `terraform/templates/user_data.sh.tftpl` | N4 |
| Orçamento mensal com aviso por e-mail | `terraform/budget.tf` | N4 |
| CI em todo pull request: Pint, testes no SQLite com cobertura e no MySQL 8.4, `composer audit`, `npm audit`, build das imagens com Trivy, Terraform e actionlint, com as actions fixadas pelo SHA | `.github/workflows/ci.yml` e `.github/dependabot.yml` | N5 (José) |
| Deploy por tag `v*`, com aprovação, OIDC (sem chave guardada), push no ECR, instance refresh, conferência do `/api/health` e rollback pelo `image_tag` | `.github/workflows/deploy.yml` e `terraform/cicd.tf` | N5 (Manoel) |

**Como foi provado:** actionlint sem aviso, 18 testes da computação e 12 do CI/CD, e os passos de cada job rodados no computador (`docs/nuvem/CI-CD.md`, "Rodar o mesmo no computador"). **Só o GitHub e a AWS provam:** o CI verde depois do push, o OIDC, o deploy, o instance refresh e o rollback.

### Sprint 4: observabilidade e entrega final (go-live)

**O que o PDF pede.** Fase 4: "implementação de ferramentas de monitoramento de performance e logs centralizados" e "execução de testes de estresse e homologação final do ambiente em produção". Entregas: "dashboards de métricas, relatórios de testes e fechamento do projeto". A Sprint 4 do Leonam estava pronta desde 29/10/2026, mas num repositório local fora do repositório do grupo; ela entrou aqui na N6.

| Entrega | Onde está | Branch |
|---|---|---|
| Monitoramento da homolog: Prometheus, Grafana com o painel "BarberFlow: visão geral" (10 painéis em 3 linhas), Loki e Alloy com os logs de todos os containers, cAdvisor e os exporters | `docker-compose.monitoring.yml` e `monitoring/` | N6 |
| Alarmes do CloudWatch (12 na homolog e 15 na prod) com aviso por e-mail e o dashboard | `terraform/monitoring.tf` | N6 |
| Testes de carga com login (um token por usuário virtual), concorrência da agenda e o roteiro de resiliência | `load-tests/` | N6 |
| Checklist de go-live, diretrizes de segurança com a LGPD, custos e o desenho da arquitetura | `docs/nuvem/go-live-checklist.md`, `SEGURANCA.md`, `CUSTOS.md` e `ARQUITETURA-NUVEM.md` | N6 |
| Material da Sprint 4 de 29/10, como referência | `docs/nuvem/sprint4/`, `docs/nuvem/prints/` e `load-tests/sprint4/` | N6 |
| Fechamento: a verificação completa, este relatório, o roteiro da apresentação e os prints | seção 4, `docs/APRESENTACAO.md` e `docs/prints-telas/` | F |

**Como foi provado:** na homolog local, com as imagens de produção (seção 4). **O que ficou de fora:** a homologação final "em produção", na AWS, porque precisa da conta do grupo e custa dinheiro (perto de US$ 62 por mês só a homolog, `docs/nuvem/CUSTOS.md`). O roteiro está pronto: `docs/nuvem/TERRAFORM.md`, "Primeiro deploy", e a parte B do `docs/nuvem/go-live-checklist.md`.

## 4. Evidências da verificação

### Ambiente

- Windows 11 Pro, AMD Ryzen 7 5700X3D (8 núcleos e 16 threads), 31,9 GB de RAM.
- Docker Desktop com Docker Engine 29.5.3 e Compose v5.1.4, no WSL2; a máquina virtual do Docker com 16 CPUs e 15,6 GB.
- Node 24.21.0 e npm 11.19.0 no computador. O PHP roda só em container: `composer:2` (PHP 8.5.12) e `php:8.4.27-cli` no teste do MySQL.
- Código: a `feat/entrega-final` no commit `e4f78b1`, com as mudanças da etapa de pendências da aplicação (o `DemoSeeder`, o erro de banco no log sem o SQL e os timeouts curtos). As correções da revisão final vieram depois, no mesmo dia (seção 4.10): com elas rodaram de novo os testes, o Pint e o build, os prints da Minha conta do barbeiro, o "subir de novo sem perder nada" e a queda do banco com volume. Depois, a rodada de acabamento (seção 4.10) corrigiu os achados da seção 8 que pediam código e rodou de novo os testes (seção 4.1), o Pint e o build, a homolog com as imagens refeitas, as quedas do banco e do cache, o servidor de e-mail fora do ar e os prints que as correções mudaram. O resto (k6, ZAP, headers e CSRF) é da verificação: as correções não mexeram em rota, limite de requisição nem cabeçalho, e as telas mudaram só no menu, nos cadastros no celular, nos blocos curtos da Agenda e no aviso do e-mail de confirmação que não saiu. A revisão da rodada de acabamento (fim da seção 4.10) mudou ainda três gravações, que passaram para dentro de uma transação, e o texto fixo da tela "Confirme seu e-mail"; com ela, os testes rodaram de novo no SQLite, no MySQL e na cópia igual à do CI (seção 4.1), o Pint e o build também, e a homolog não foi repetida. Por último, a segunda rodada de acabamento (seção 4.11) corrigiu os três achados da seção 8 que ainda pediam código (o envio de e-mail sem limite de tempo, o log do lembrete no agendador e o e-mail da tela "Confirme seu e-mail" no celular), provou que o quarto (a confirmação do e-mail contra o cadastro de cliente da barbearia) não dá 500, corrigiu um achado novo da revisão dela (no MySQL, a confirmação ligava a conta ao cadastro de cliente de outra pessoa com um e-mail só parecido, com acento), rodou de novo os testes (seção 4.1), o Pint e o build e mediu na homolog, com as imagens refeitas, o servidor de e-mail congelado e a falha do lembrete no log do agendador. As quedas do banco e do cache, o k6, o ZAP, os headers, o CSRF e os prints não foram repetidos: a segunda rodada não mexeu na conexão com o banco e o cache, em rota, em limite de requisição nem em cabeçalho, e a única tela que mudou não tem print. A correção do e-mail parecido veio depois da homolog e foi provada só nos testes, no SQLite e no MySQL: ela muda uma regra de validação do cadastro de conta e a ligação feita na confirmação, sem tela nova.

### 4.1 Testes

| Rodada | Resultado | Tempo |
|---|---|---|
| `composer test` no SQLite (PHP 8.5.12) | **745 passaram e 9 pulados**, 8535 asserções | 92,0 s |
| MySQL 8.4.13 descartável (PHP 8.4.27), o mesmo digest do job `MySQL` do CI, com `phpunit.mysql.xml` e o banco num tmpfs | **754 passaram, nenhum pulado**, 8565 asserções | 1 min 41 s de testes |
| Igual ao CI: uma cópia sem `vendor`, `node_modules`, `public/build` e `.env`, num `composer:2` descartável, com `composer install`, o `.env` do `.env.example`, `key:generate` e `composer test` | **745 passaram e 9 pulados**, 8535 asserções | 20,6 s de testes |

São as rodadas com as correções da segunda rodada de acabamento (seção 4.11), que trouxeram 12 casos novos: 2 do limite de tempo do servidor de e-mail, 2 da rodada do lembrete no processo do agendador, 2 do e-mail da tela "Confirme seu e-mail", 2 da confirmação do e-mail contra o cadastro de cliente da barbearia, só no MySQL, e 4 do e-mail parecido (a recusa do e-mail com acento no cadastro de conta, a confirmação que não liga a conta ao cadastro com o e-mail só parecido, nos dois sentidos, e a que liga quando só maiúsculas e minúsculas mudam). Antes deles, depois da revisão da primeira rodada de acabamento, eram 735 e 7 pulados no SQLite e 742 no MySQL: a primeira rodada (seção 4.10) tinha trazido 17 casos novos (o e-mail repetido no mesmo instante, o cadastro de conta com o e-mail fora do ar, o limite de leitura do MySQL, o menu numa linha só e a lista dos cadastros no celular), e a revisão dela, mais 3 (o cadastro de serviço, de cliente e de conta que perde a conexão depois de gravar). Antes das correções, a revisão final tinha dado 715 e 7 pulados no SQLite e 722 no MySQL, e a verificação de antes da revisão, 706 e 713. Os 9 pulados no SQLite são os de trava (`AppointmentConcurrencyTest`, `PaymentConcurrencyTest`, `LoyaltyConcurrencyTest` e `EmailVerificationConcurrencyTest`), que rodaram e passaram no MySQL (754 = 745 + 9). A rodada igual à do CI prova que nenhum teste depende do `public/build`, do `vendor` ou do `.env` da pasta de trabalho; na rodada de acabamento, foi ela que achou um teste novo que só passava sem o `DB_READ_TIMEOUT` no `.env` (seção 4.10), e, na segunda, ela passou também com o `MAIL_TIMEOUT=10` que o `.env.example` leva para o `.env`.

### 4.2 Qualidade

| O quê | Resultado |
|---|---|
| Pint | Passou nos 132 arquivos (126 na verificação, 128 depois da revisão final, 129 na rodada de acabamento, antes da revisão dela, e 131 depois dela) |
| `npm run build` (Vite 8.3.5) | 62 módulos; o CSS com 31,81 kB e o JS com 42,12 kB depois do gzip (31,80 kB e 41,71 kB antes da rodada de acabamento) |
| `npm audit` | Nenhuma vulnerabilidade |
| `composer audit` | **1 alerta de severidade baixa:** GHSA-63hm-g96r-2m2q no `laravel/framework` 13.33.0, corrigido na 13.40.0. Espera o OK do Leonam (seção 7) |

### 4.3 Homolog no ar

- **Imagens:** `app` (767 MB) e `web` (93,2 MB), construídas do `docker/php/Dockerfile` como no `docs/nuvem/DOCKER.md`.
- **Subida:** 13 containers saudáveis em 18,2 s, com o monitoramento; o `app` rodou as migrations na subida. Portas só no 127.0.0.1: 8080, 8025, 3000 e 9090. O MySQL ficou num tmpfs (seção 7).
- **`php artisan about` no `app`:** Laravel 13.33.0, PHP 8.4.27, `production`, debug desligado, configuração, rotas e views em cache, cache e sessão no Redis, log no stderr e e-mail pelo Mailpit. O PHP-FPM roda como `www-data`.
- **Dados:** o `DemoSeeder` criou 180 agendamentos e 102 pagamentos, e o `AdminUserSeeder`, o admin, com as senhas só no ambiente do comando. Depois do roteiro de resiliência (que apaga o banco do tmpfs), uma rodada nova criou 177 e 95, e a segunda rodada não duplicou nada. Os números mudam com o dia: o seeder monta os 30 dias em volta da data em que roda.
- **Navegador:** login como admin e as 8 telas dele com a API em 200 e o console vazio, com a CSP ligada; pela API, um `POST` (201) e um `DELETE` (204).
- **Logins pela API:** o admin viu os 177 agendamentos; o barbeiro `carlos.mendes`, os 59 dele; a cliente `ana.ribeiro`, os 13 dela, com 403 em `/api/clients`.
- **Monitoramento:** `/api/health` com banco e cache `ok` em 9 ms; os 4 alvos do Prometheus `up`, com `mysql_up` e `nginx_up` em 1; o Grafana 13.3.1 com o painel; o Loki com os logs dos 13 serviços.

### 4.4 Testes de carga (k6)

Na homolog de carga (os limites de requisição levantados só nela), com o k6 `grafana/k6:2.3.0` numa rede só dele e do `web`. Metas: p95 abaixo de 500 ms, menos de 1% de erro e mais de 99% das conferências certas, contando só as requisições do cenário. **Os números valem para este computador e não se comparam com os de uma instância da AWS.**

| Cenário | VUs | Requisições | Mediana | p95 | p99 | Máximo | Erros | Metas |
|---|---|---|---|---|---|---|---|---|
| smoke | 1 | 145 | 11 ms | 15,5 ms | 16,1 ms | 19,8 ms | 0,00% | Passou |
| carga | até 50 | 11.113 | 11,2 ms | 16,8 ms | 18,9 ms | 28,0 ms | 0,00% | Passou |
| estresse | até 200 | 26.634 | 11,9 ms | 17,6 ms | 20,3 ms | 33,5 ms | 0,00% | Passou |

No pico do estresse, pelo cAdvisor: o `app` (10 processos do PHP-FPM) chegou a 1,92 núcleo de CPU e 57 MB; o `db`, a 0,53 núcleo e 737 MB (o tmpfs conta como memória).

**Concorrência da agenda** (`concorrencia.js`): mesmo cliente, mesmo barbeiro, mesmo serviço, mesmo dia e mesmo horário, todos no mesmo instante.

| Pedidos ao mesmo tempo | Criados (201) | Recusados com 422 | 409, 500 ou erro de rede |
|---|---|---|---|
| 10 | **1** | 9 | 0 |
| 50 | **1** | 49 | 0 |

Para comparar com a Sprint 4 (29/10/2026, sem login, com outra API e outro script): a rodada "otimizada" daquela época ficou com p95 de 95 ms na carga e 24 ms no estresse (`load-tests/sprint4/RESULTADOS.md`). Os resumos de agora estão em `load-tests/results/f1-*.json`.

### 4.5 Resiliência

Roteiro do `load-tests/resiliencia/README.md`, na homolog com os limites normais, primeiro os casos que não apagam nada:

| Caso | `/api/health` durante a queda | `/up` | Volta |
|---|---|---|---|
| Cache congelado (`pause redis`) | 503 em 3,02 s | 200 | 200 logo depois do `unpause` |
| Cache fora (`stop redis`) | 503 em 3,94 s | 200 | 200 logo depois do `start` |
| PHP-FPM cai (`kill -TERM 1`) | | 502 na hora | `/up` 200 em 1,7 s; o Docker reiniciou o container |
| App parado (`stop app`) | 504 em 2,00 s | 504 em 2,00 s | `/api/health` 200 em 0,7 s depois do `start` |
| Banco congelado (`pause db`), antes do limite de leitura | **504 em 60,00 s** | 200 | 200 logo depois do `unpause` |
| Banco congelado, com o limite de leitura (rodada de acabamento, seção 4.10) | **503 em 20,05 s** | 200 | 200 em 0,03 s depois do `unpause` |
| Banco fora (`stop db`) | 503 em 13,30 s | 200 | MySQL saudável em 8,8 s |

- O `/up` respondeu 200 em todos os casos menos com o app fora: é raso, como o ALB precisa.
- **Timeouts curtos, medidos à parte** (o código do `/api/health` num container descartável, com o banco ou o cache apontando para um endereço que descarta toda conexão nova): banco em 503 em 12,03 s (o Laravel tenta 4 vezes, 4 vezes os 3 s), cache em 503 em 3,02 s. Com 10 s no lugar dos 3, 40 s e 10 s.
- **O banco fora levou 13,3 s** porque o nome do serviço parado some do DNS do Docker, e cada procura leva 4,6 s, que nenhum timeout limita. Na AWS o endereço do RDS continua existindo, e vale o caso do endereço que descarta.
- **O banco congelado foi um achado** (seção 8): a conexão abre e o PHP esperava a resposta sem limite curto, até o 504 do Nginx. Com o limite de leitura (`DB_READ_TIMEOUT`), que entrou na rodada de acabamento, o mesmo caso passou a dar 503 em 20 s, e o processo do PHP-FPM volta livre com o banco ainda congelado (seção 4.10). As outras linhas são da verificação; a rodada de acabamento mediu de novo o cache congelado e o banco fora, com os mesmos números.
- **Log de erro de banco:** nas quedas, 3 linhas `Erro no banco de dados.` chegaram ao Loki, só com a conexão, os códigos e o arquivo:linha, sem SQL, e-mail ou pilha.

### 4.6 Headers e CSRF

- **Headers:** os 6 de segurança (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` e a CSP) em telas, API, erros e `/up`. Nenhuma resposta com `X-Powered-By`, e o `Server` só com `nginx`. Sem HSTS em HTTP, nem com um `X-Forwarded-Proto: https` falso. O HSTS em HTTPS fica provado pelos testes, porque a homolog não tem TLS.
- **CSRF no navegador:** um `POST /login` sem o token, vindo de outro site, de outra porta ou de um cliente sem o header `Sec-Fetch-Site`, recebeu 419. Vindo da própria tela, passou. É o comportamento do Laravel 13.33: o `PreventRequestForgery` aceita o pedido quando o navegador manda `Sec-Fetch-Site: same-origin` ou quando o token confere. O plano esperava o 419 também na mesma origem; a explicação completa está no `docs/nuvem/SEGURANCA.md`, "CSRF no navegador".

### 4.7 OWASP ZAP baseline

- `zaproxy/zap-stable:2.17.0`, fixado pelo digest, só contra a homolog local, com spider de 1 minuto e só as regras passivas.
- **0 FAIL, 4 WARN e 58 PASS.** Sem login, ele só viu as telas abertas (11 endereços).
- Os 4 avisos: o cookie `XSRF-TOKEN` sem `HttpOnly` (de propósito no Laravel), o corpo do 302 do Symfony (só com o endereço de destino) e dois informativos, que só dizem que ele reconheceu o login e a sessão. A leitura de cada um está no `docs/nuvem/SEGURANCA.md`.

### 4.8 Terraform

Com a função `tf` do `docs/nuvem/TERRAFORM.md` (`hashicorp/terraform:1.13.3` fixado pelo digest, provider `hashicorp/aws` 6.73.0). Nada de `apply`, `plan`, `import` nem `state`.

| Comando | Resultado |
|---|---|
| `tf fmt -check -recursive` | Sem diferença |
| `tf init -backend=false` e `tf validate` | `Success! The configuration is valid.`; o lock não mudou |
| `tf test` | **69 passaram, 0 falharam**: 12 do CI/CD, 18 da computação, 19 de dados e segurança, 12 do monitoramento e 8 da rede |
| `tf -chdir=bootstrap test` | 1 passou |
| `trivy config` (`aquasec/trivy:0.76.2`, fixado pelo digest) | 0 configurações inseguras em todos os arquivos |
| `actionlint` 1.7.12, com o ShellCheck | Nenhum aviso nos dois workflows |
| ShellCheck 0.11.0 no `user_data` pronto | Nenhum aviso |

### 4.9 Prints das telas

44 prints em `docs/prints-telas/` (20 do admin, 10 do barbeiro, 6 da cliente e 8 do visitante), em 1280 e 390 px, tirados em 15/11/2026 na homolog, com os dados do `DemoSeeder`. Os 2 da Minha conta do barbeiro entraram na revisão final (seção 4.10). Ficaram sem print a "Redefinir senha", que só abre pelo link do e-mail, e as páginas de erro 403, 419, 429, 500 e 503, com o mesmo layout da 404. Nenhum mostra senha, token ou e-mail de verdade, e nenhuma tela de 390 px tem rolagem lateral. Os três detalhes visuais que eles mostraram (as tabelas dos cadastros no celular, o menu do admin em 1280 px e os blocos curtos da Agenda) foram corrigidos depois, na rodada de acabamento (seção 8). No mesmo dia, os 22 prints que as correções mudaram (os 18 de 1280 px com login e os 4 dos cadastros em 390 px) foram refeitos na homolog, com os mesmos dados, logins e registros abertos; os outros 22 foram tirados de novo e saíram iguais aos de antes, pixel a pixel (`docs/prints-telas/README.md`, "Refeitos na rodada de acabamento").

### 4.10 Revisão final e rodada de acabamento

Depois da verificação, dois revisores de contexto limpo leram as mudanças da entrega final, um olhando a correção e as regras do `AGENTS.md`, outro a segurança. O que eles confirmaram foi corrigido no mesmo dia (15/11/2026):

| Achado | O que mudou | Prova |
|---|---|---|
| A falha no envio de e-mail (confirmação de conta, "esqueci a senha" e reenvio do link) ia para o log com o endereço de quem ia receber, e o "esqueci a senha" respondia 500 só para e-mail cadastrado | O `report` do `bootstrap/app.php` grava a falha do Symfony Mailer só como "Falha no envio de e-mail.", com a classe, o código e o arquivo:linha, e o `PasswordResetter::sendLink` responde o mesmo com o e-mail fora do ar | `MailErrorLogTest` |
| O `AdminUserSeeder` criava o admin com qualquer senha, até de 1 caractere | A mesma regra das contas (no mínimo 8 caracteres), dividida com o `DemoSeeder` (`database/seeders/Concerns/ChecksPasswordRule.php`) | `DatabaseSeederTest::test_admin_seeder_refuses_a_password_below_the_rule_of_the_accounts` |
| O `DemoSeeder` chamava o `ServiceSeeder` e devolvia os 4 serviços ao preço e ao status padrão em toda rodada | Ele cria só os serviços de exemplo que nunca existiram, e o que o admin mudou fica | `DemoSeederTest::test_running_again_does_not_duplicate_or_change_anything` |
| O `DatabaseErrorLogTest` tratava como esperado o 500 de dois cadastros com o mesmo e-mail ao mesmo tempo | O teste confere só o log e que a resposta é um erro do servidor, e a corrida entrou nos achados (seção 8), corrigidos depois na rodada de acabamento | `DatabaseErrorLogTest` |
| O README dizia que todos os números ficam no `.env` | O texto separa o que vem do `.env` do que é fixo no `config/barberflow.php` | `README.md` |
| Faltavam os prints da Minha conta do barbeiro | Os 2 prints, em 1280 e 390 px | `docs/prints-telas/` |

Com as correções, a suíte rodou de novo (seção 4.1), e a homolog subiu outra vez, com as imagens reconstruídas, sem o monitoramento e com o MySQL num volume novo e persistente (`barberflow-homolog_dbdata-r`, porque o antigo tem a senha perdida):

- **Dados:** o `DemoSeeder` criou 177 agendamentos e 95 pagamentos e, na segunda rodada, nada. O `AdminUserSeeder` criou o admin e, com uma senha de 7 caracteres, respondeu "Administrador inicial não criado. O campo ADMIN_PASSWORD deve ter pelo menos 8 caracteres.", sem criar ninguém.
- **Subir de novo sem perder nada (go-live A15):** `down`, sem `-v`, em 2,6 s, e `up` em 11,8 s, com "Nothing to migrate". O `/api/health` voltou em 200, com os mesmos 177 agendamentos e 95 pagamentos, o token da API de antes do `down` e a sessão do navegador valendo.
- **Banco fora com o volume:** `/api/health` em 503 em 13,3 s com o banco parado e de volta a 200 em 1,9 s depois do `start`, sem `restart` do app, migrations ou seeders. Os dados e o token continuaram. No tmpfs, o mesmo caso apagava o banco (seção 4.5).

**Rodada de acabamento.** Ainda em 15/11/2026, uma última rodada corrigiu os seis achados da seção 8 que pediam código (três das telas e três do servidor; o que mudou em cada um e o teste que prova estão lá), rodou de novo os testes (seção 4.1), refez os prints que as correções mudaram (seção 4.9) e mediu de novo na homolog, entre 9h45 e 10h10, com as imagens refeitas a partir da pasta de trabalho, sem o monitoramento e com o MySQL no mesmo volume `barberflow-homolog_dbdata-r`:

- **Subida:** as imagens com o código novo dentro (o manifest do Vite igual ao do `npm run build` da pasta de trabalho) e os 6 containers saudáveis em 11,9 s, com "Nothing to migrate" e sem seeder: os mesmos dados da revisão final. Como admin, 7 telas com a API em 200 e o console vazio.
- **Banco congelado, com o limite de leitura** (`pause db`, `DB_READ_TIMEOUT` de 5 s), comparado com a verificação (seção 4.5):

| Medida | Antes do limite | Com o limite |
|---|---|---|
| `/api/health` | 504 em 60,00 s | **503 em 20,05 s** |
| API com token | 504 em 60,06 s | **500 em 20,03 s** |
| Tela com sessão (`/dashboard`) | Não medida | **500 em 20,03 s** |
| Processo do PHP-FPM | Preso até o banco voltar | Livre cerca de 20 s depois de cada pedido, com o banco ainda congelado |
| Log | Nenhuma linha | Uma linha `Erro no banco de dados.` (código 2006) por pedido, sem SQL e sem e-mail |
| Cache congelado: `/api/health`, `/login` e API com token | 503 em 3,02 s, 500 em 3,01 s e 500 em 3,02 s | 503 em 3,01 s, 500 em 3,01 s e 500 em 3,02 s |
| Banco fora: `/api/health` e API com token | 503 em 13,30 s e 500 em 12,43 s | 503 em 13,31 s e 500 em 12,48 s, de volta em 1,19 s depois do `start`, com os mesmos dados |

- **Os 20 s** são as 4 tentativas do Laravel (ele repete a conexão e a consulta quando a conexão parece perdida) vezes os 5 s do limite, um terço dos 60 s do Nginx.
- **Pool cheio:** 10 pedidos ao mesmo tempo com o banco congelado, o tamanho do pool, ocuparam os 10 processos do PHP-FPM, e um `/up` mandado no meio esperou 16,79 s. Os 10 responderam 503 entre 20,02 e 23,11 s, e o `/up` voltou a 0,01 s logo depois, ainda com o banco congelado. É o risco que sobra (seção 8).
- **Servidor de e-mail fora** (`stop mailpit`), com duas contas de cliente novas, de e-mail do domínio de teste: o cadastro pela API respondeu 201 com o aviso de que o link não saiu (4,31 s), e o reenvio do link, 503 (3,13 s). Pela tela, o cliente entrou na tela "Confirme seu e-mail" com o aviso amarelo, que voltou no reenvio. O log ganhou 5 linhas `Falha no envio de e-mail.`, sem nenhum endereço. Com o Mailpit de volta, o reenvio deu 202 e o e-mail chegou. As duas contas ficaram na homolog sem confirmar e não viraram cliente: a tela Clientes continua com os 15 da demonstração.
- **Servidor de e-mail congelado** (`pause mailpit`, medido a mais): o reenvio levou 60,03 s até o 503, porque o SMTP não tinha limite de tempo, e a rodada do lembrete que caiu no meio esperou 55 s. Ficou nos achados em aberto e foi corrigido na segunda rodada de acabamento (seção 4.11).
- **A rodada igual à do CI achou um teste frágil:** o teste novo do limite de leitura só passava sem o `DB_READ_TIMEOUT` no `.env`. Com o `.env` copiado do `.env.example`, como no CI, o Laravel deixava o `.env` escrever por cima do valor do teste quando o app subia de novo. O teste passou a começar com um repositório de variáveis novo, e a rodada passou (seção 4.1).

**Revisão da rodada de acabamento.** Dois revisores de contexto limpo leram as mudanças da rodada, de novo um olhando a correção e outro a segurança. O que eles confirmaram e pedia código foi corrigido no mesmo dia, com testes:

| Achado | O que mudou | Prova |
|---|---|---|
| Com o `DB_READ_TIMEOUT`, um `INSERT` lento fora de transação podia desistir depois que o banco gravou, e o Laravel repetia o comando numa conexão nova: o serviço saía em dobro, e o cliente e a conta de cliente recebiam o 422 de e-mail em uso já criados (a conta, sem o e-mail de confirmação) | Os três `INSERT` (`ServiceController::store`, `ClientController::store` e `ClientRegistration::register`) vão dentro de um `DB::transaction()`: o Laravel não repete o comando, e a conexão que cai sem `COMMIT` desfaz a gravação. O pedido responde 500, sem nada gravado | `LostConnectionWriteTest`, com o `loseConnectionAfter()` do `tests/TestCase.php`, que faz o `INSERT` gravar e dar o erro 2006 logo depois. Sem a transação, o serviço respondia 201 e o cliente e a conta, 422 |
| A tela "Confirme seu e-mail", recarregada depois do cadastro ou do reenvio que falharam, voltava a dizer "Enviamos um link de confirmação": o aviso amarelo vale para uma vez só | O texto fixo da tela só diz que falta confirmar o e-mail. Quem diz que o link saiu é o aviso verde do cadastro (novo) e do reenvio que deram certo | `MailErrorLogTest` (a tela aberta de novo depois do cadastro e do reenvio que falharam), `RegistrationTest::test_web_registration_logs_in_and_shows_the_confirmation_notice` e `WebPagesTest::test_email_confirmation_notice_responds_ok` |

Os outros achados eram dos docs: o `AGENTS.md` e o checklist do README diziam que a rodada mediu tudo de novo na homolog (k6, ZAP, headers e CSRF não foram repetidos, e o 422 do e-mail repetido foi provado só nos testes), e a árvore de pastas da `docs/ARQUITETURA.md` não trazia o `EmailTakenException`. A repetição das leituras lentas ficou em aberto (seção 8). Com a transação nova, os testes de corrida do e-mail repetido de clientes e de conta passaram a gravar o outro cadastro logo depois da consulta da regra `unique` (o `afterQuery()` do `tests/TestCase.php`), antes da transação, para ele continuar no banco quando ela é desfeita, como num banco de verdade.

### 4.11 Segunda rodada de acabamento

Ainda em 15/11/2026, uma segunda rodada corrigiu três dos achados que a primeira deixou em aberto na seção 8 e conferiu o quarto, com testes. A revisão dela achou um quinto, de segurança, também corrigido (a última linha):

| Achado | O que mudou | Prova |
|---|---|---|
| O envio de e-mail não tinha limite de tempo (`config/mail.php`, `'timeout' => null`): com o servidor de e-mail congelado, o reenvio do link levou 60,03 s, empatado com os 60 s do Nginx, e a rodada do lembrete esperou 55 s | O `MAIL_TIMEOUT` (padrão 10 s; vazio ou 0 vale o padrão), novo no `.env.example`: o SMTP desiste em 10 s quando o servidor não manda nem a saudação, ou em até 20 s quando ele congela no meio da conversa, porque o comando que ficou sem resposta e o `QUIT` esperam o limite cada um. Na AWS, sem parâmetro no SSM, vale o padrão | `ConnectionTimeoutTest` (`test_smtp_gives_up_after_10_seconds_by_default` e `test_the_smtp_timeout_comes_from_the_environment`, que conferem também o valor que chega à conexão do Symfony). Numa sonda no container local, com um servidor congelado antes da saudação: 10,04 s com o padrão e 3,02 s com 3; congelado no meio da conversa: 20,04 s e 6,02 s |
| O log do lembrete que falhava se perdia na homolog e na AWS: o agendador rodava o `appointments:send-reminders` como outro processo, com a saída no `/dev/null`, e ali o log da aplicação vai para o `stderr` | A rodada roda dentro do processo do agendador (`Schedule::call`, com o nome `appointments:send-reminders`, em `routes/console.php`), com a mesma regra do comando (`ReminderSender::sendDue()`). Com alguma falha, ela termina como falha, com uma linha no log só com as contas. O comando continua, para rodar a rodada na mão | `ReminderCommandTest` (`test_the_scheduled_round_logs_the_failed_send_in_the_scheduler_process`, `test_the_scheduled_round_sends_the_reminders_without_logging` e `test_the_round_runs_every_15_minutes_on_one_server_without_overlapping`): os dois primeiros rodam o `schedule:run`, e os três reprovam com o `Schedule::command` de antes |
| Em 390 px, a tela "Confirme seu e-mail" quebrava um e-mail longo no meio da palavra | O e-mail sai com um `<wbr>` depois do @ e antes de cada ponto, como nas tabelas, com cada pedaço escapado pelo `e()` do Blade | `WebPagesTest` (`test_email_confirmation_notice_breaks_the_email_only_after_the_at_and_before_the_dots` e `test_email_confirmation_notice_escapes_each_piece_of_the_email`). No navegador do Orca, em 390 px, o e-mail quebrou só nesses pontos, sem rolagem lateral |
| Uma conta que confirmasse o e-mail no mesmo instante em que a barbearia cadastra um cliente com o mesmo e-mail receberia 500 (achado pela leitura do código) | Nada: a corrida não dá 500. No MySQL, a busca com trava do `LinkClientToVerifiedUser` trava o intervalo do índice `unique` de `clients.email` (gap lock): o insert da barbearia espera a confirmação e recebe o 422 de e-mail em uso, ou a confirmação espera o commit da barbearia e liga a conta ao cadastro novo. No SQLite, as transações `IMMEDIATE` fazem uma gravação esperar a outra | `EmailVerificationConcurrencyTest` (2 testes, só no MySQL), duas sessões de verdade num MySQL 8.4.13 descartável e uma sonda no SQLite com as transações do app |
| No MySQL, a confirmação do e-mail ligava a conta ao cadastro de cliente de outra pessoa com um e-mail só parecido: a collation `utf8mb4_unicode_ci` não diferencia acento, e a busca por `maria@gmáil.com` (o domínio `xn--gmil-6na.com`, que qualquer um registra e onde o link de confirmação chega) acha o cadastro de `maria@gmail.com`. Quem confirmasse o endereço parecido via o histórico, o telefone, os pagamentos e a fidelidade da dona do e-mail. Achado pela revisão de segurança da segunda rodada, na leitura do código; o SQLite dos testes compara letra por letra e não pegava | A confirmação só liga a conta a um cadastro com o mesmo e-mail, sem contar maiúsculas e minúsculas, que não mudam o destino (`LinkClientToVerifiedUser::SIMILAR_EMAIL`): com o e-mail só parecido, a conta fica sem cadastro e o log leva só os ids. O cadastro de conta passou a recusar e-mail com acento (regra `ascii`): sem ela, a conta parecida também tomava o lugar da conta da dona do e-mail, que recebia "Este e-mail já está cadastrado.", e o "esqueci minha senha" dela mandava o link para o endereço parecido | `RegistrationTest` (`test_confirming_the_email_does_not_take_a_client_with_a_similar_email`, nos dois sentidos, `test_confirming_the_email_links_the_client_whose_email_differs_only_in_capital_letters` e `test_registration_refuses_an_email_with_an_accent`), com a comparação do MySQL imitada no SQLite (uma collation sem maiúsculas e sem acento no `clients.email`) e a de verdade no MySQL 8.4.13 descartável. Sem a correção, os dois primeiros reprovam (a conta é ligada ao cadastro da outra pessoa), e o de maiúsculas reprova com uma comparação letra por letra no lugar da nova |

Com as correções, a suíte rodou de novo no SQLite, no MySQL e na cópia igual à do CI (seção 4.1), o Pint passou nos 132 arquivos e o build saiu com os mesmos arquivos (seção 4.2).

**Na homolog**, entre 13h25 e 14h02, com as imagens refeitas (a `app` com o código novo; a `web` igual, porque o front não mudou), sem o monitoramento, com o MySQL no mesmo volume `barberflow-homolog_dbdata-r` e sem seeder: os 6 containers saudáveis em 12,0 s, o `/api/health` em 200 e, dentro do `app` e do `scheduler`, o `mail.mailers.smtp.timeout` em 10. Com o Mailpit congelado (`pause mailpit`):

| Medida | Antes (primeira rodada) | Agora |
|---|---|---|
| Reenvio do link (`POST /api/email/verification-notification`) | 503 em 60,03 s | **503 em 10,04 s e 10,03 s** |
| Cadastro de conta (`POST /api/register`) | Não medido (o mesmo limite de 60 s) | **201 em 10,42 s**, com o aviso de que o link não saiu |
| "Enviar lembrete agora" | Não medido (o mesmo limite de 60 s) | **503 em 10,06 s**, com o `reminder_sent_at` de volta a nulo |
| Rodada do lembrete | 55 s presa num lembrete só, até o `unpause` | **30 s para 3 lembretes**, 10 s cada |
| Log da falha do lembrete no agendador | Nenhuma linha: o comando agendado tinha a saída no `/dev/null` | **3 avisos `Lembrete por e-mail não enviado.`**, cada um só com o `appointment_id`, e 1 `ERROR` só com as contas ("Lembretes por e-mail com falha nesta rodada: 3. Enviados: 0. Pulados: 0."), no log do container `scheduler` |
| A rodada seguinte, com o Mailpit de volta | A primeira rodada não provocou a falha | Mandou os 3 lembretes em 0,23 s, com o `reminder_sent_at` preenchido |

- O log do `app` ficou com 3 linhas `Falha no envio de e-mail.` (o cadastro e os dois reenvios), sem nenhum endereço. Nas 39 linhas do agendador na rodada que falhou, nenhum nome, e-mail ou telefone dos 15 clientes e das 11 contas.
- Com o Mailpit de volta, o reenvio deu 202 em 0,04 s, e o link chegou.
- O servidor que congela no meio da conversa (até 20 s) não deu para provocar na homolog, porque o `pause` do Docker congela o Mailpit antes da saudação: vale a sonda local (20,04 s).
- A homolog ficou com uma conta de cliente a mais, a do cadastro pela API, de e-mail do domínio de teste, sem confirmar e sem cadastro de cliente, e com os 3 lembretes de 16/11 enviados pela rodada das 14h. No fim, ela foi derrubada sem `-v`, com os 5 volumes no lugar.
- **Achado novo, do Laravel:** a linha da rodada no log do agendador saiu `30s DONE`, e não `FAIL`, com a rodada falhando (seção 8).

A segunda rodada achou ainda dois pontos no código que ela não mexeu, que ficaram em aberto (seção 8): duas confirmações de e-mail no mesmo instante dão deadlock no MySQL, e a Minha conta quebra um e-mail longo no meio da palavra em 390 px.

## 5. Faltas da auditoria de 12/11/2026

A auditoria de 12/11/2026 levantou as faltas nas duas réguas, em quatro listas (API, front, nuvem e qualidade). Um verificador conferiu cada uma (confirmada, parcial ou refutada) e uma revisão crítica acrescentou 13 faltas novas e 62 ajustes. Tirando os duplicados e a refutada (nota no fim da seção), ficam as 96 faltas das tabelas abaixo.

Cada linha diz em que branch a falta foi resolvida, com o arquivo que prova, ou por que ela ficou fora do escopo (o §7 do plano, resumido na seção 6). As branches são as da seção 1.

### API

| Falta | Situação | Prova |
|---|---|---|
| `auth-sanctum-login` (Sanctum, login, logout e `auth:sanctum` nas rotas) | Feito em A3 | `routes/api.php`, `app/Http/Controllers/AuthController.php` e `AuthApiTest` |
| `appointments-recurso` (migration, model, controller, rota e listagem por data e barbeiro) | Feito em A4 | `database/migrations/2026_11_13_140000_create_appointments_table.php`, `AppointmentController` e `AppointmentApiTest` |
| `appointments-sem-sobreposicao` (sem sobreposição por barbeiro, com trava) | Feito em A4; provado com pedidos simultâneos na F | `App\Services\AppointmentScheduler`, `AppointmentConcurrencyTest` e `load-tests/results/f1-50vus-concorrencia.json` |
| `horarios-livres` | Feito em A4 | `AvailableSlotController` e `AvailableSlotsTest` |
| `perfis-permissoes` (perfis, vínculo com barbeiros e clientes e autorização por objeto) | Feito em A3 (Policies da agenda e dos pagamentos em A4 e A5) | `app/Policies`, `AuthorizationTest`, `AppointmentAuthorizationTest` e `PaymentAuthorizationTest` |
| `status-atendimento` | Feito em A4 | `app/Enums/AppointmentStatus.php` e `PATCH /api/appointments/{id}/status` |
| `payments` | Feito em A5 | `App\Services\PaymentRecorder` e `PaymentApiTest` |
| `dashboard-basico` | Feito em A5 | `App\Services\DashboardMetrics` e `DashboardApiTest` |
| `colisao-nomes-rotas` (o menu abria o JSON e o `route:cache` quebrava) | Feito em A1 | `bootstrap/app.php` (rotas da API com o nome `api.`) e `WebPagesTest::test_route_names_are_unique` |
| `merge-fix-stack-local` (seeders repetíveis, `phpunit.xml` com `force` e testes de seed) | Feito em A1, por cópia manual da `fix/stack-local` | `database/seeders/DatabaseSeeder.php`, `ServiceSeeder.php`, `phpunit.xml` e `DatabaseSeederTest` |
| `testes-novos-recursos` (auth, agenda, horários livres, status, pagamentos e dashboard) | Feito de A3 a A6 | `tests/Feature` (745 no SQLite e 754 no MySQL, seção 4.1) |
| `docs-api-postman-novos-endpoints` | Feito de A3 a A6 | `docs/API.md` e `docs/BarberFlow.postman_collection.json` |
| `paginacao-listagens` | Feito em A2 (agenda e pagamentos em A4 e A5) | `ServiceApiTest::test_page_comes_as_data_links_and_meta` |
| `form-requests-api-resources` | O cast do `price` foi feito em A2. Form Requests e API Resources ficaram fora do escopo, porque não estão em nenhuma das duas réguas (§7 do plano) | `app/Models/Service.php` (casts) |
| `soft-delete-historico` | Feito em A2 | `database/migrations/2026_11_13_120000_add_soft_deletes_to_domain_tables.php` e `ClientApiTest::test_deleting_a_client_anonymizes_the_personal_data` |
| `validacao-pt-br` (mensagens e o 404 sem o nome da classe) | Feito em A2 | `lang/pt_BR/` e `ApiErrorsTest` |
| `timezone-agenda` | Feito em A2 | `config/app.php` (`APP_TIMEZONE`) e `LocalizationTest` |
| `rate-limit-api` | Feito em A3 | `AppServiceProvider::configureRateLimiting` e `RateLimitTest` |
| `barbers-status-comissao` | Fora do escopo, porque a própria `docs/ARQUITETURA.md` adiou o status e a comissão do barbeiro (§7 do plano). O vínculo com o login entrou em A3 | `docs/ARQUITETURA.md`, "Diferenças em relação ao esboço inicial" |
| `lembretes-fidelidade` | Feito em A6 (decisão 1 do plano) | `App\Services\ReminderSender`, `App\Services\LoyaltyService`, `ReminderCommandTest` e `LoyaltyApiTest` |

### Front

| Falta | Situação | Prova |
|---|---|---|
| `front-readme-npm-vite` (o README não explicava Node, npm e Vite) | Feito em A1 | `README.md`, "Como rodar" |
| `front-login-logout-real` | Feito em A3 | `app/Http/Controllers/Auth/SessionController.php` e `WebAuthTest` |
| `front-protecao-rotas-web` | Feito em A3 | `routes/web.php` (`auth` e `can:`) e `WebAuthTest::test_visitor_is_sent_to_the_login` |
| `front-home-welcome` | Feito em A3 (a `welcome` saiu, e a `/` leva à tela inicial do perfil) | `app/Http/Controllers/HomeController.php` e `WebAuthTest::test_login_takes_each_profile_to_its_home` |
| `front-agenda-por-data` | Feito em A4 | `resources/views/appointments/index.blade.php` e `resources/js/pages.js` |
| `front-agendar-post-conflito` | Feito em A4 (o erro aparece no campo e, com o horário ocupado no meio do caminho, a tela avisa e busca os horários de novo) | `resources/views/partials/appointment-form.blade.php` e `resources/js/pages.js:2727-2742` |
| `front-status-atendimento` | Feito em A4 (as ações no detalhe da Agenda) | `WebPagesTest::test_agenda_gets_the_business_hours_and_the_status_rules_from_the_server` |
| `front-pagamentos` | Feito em A5 | `resources/views/payments/index.blade.php` e `resources/views/partials/payment-modal.blade.php` |
| `front-dashboard` | Feito em A5 | `resources/views/dashboard/index.blade.php` e `WebPagesTest::test_dashboard_page_has_the_period_choices_the_cards_and_the_tables` |
| `front-menu-por-perfil` | Feito em A3 | `resources/views/layouts/app.blade.php` (`@can`) e `WebPagesTest::test_menu_shows_only_the_pages_of_the_profile` |
| `front-agendamento-online-cliente` | Feito em A4 (decisão revista em 13/11: o cliente tem conta), sem carregar `/api/clients` | `resources/views/appointments/booking.blade.php` e `WebPagesTest::test_client_booking_form_has_no_client_field` |
| `front-mensagens-pt-br` (páginas de erro em português) | Feito em A3 | `resources/views/errors/` e `WebPagesTest::test_error_pages_are_in_portuguese` |
| `front-testes-rotas-web` | Feito em A1 e ampliado de A3 a A6 | `tests/Feature/WebPagesTest.php` |
| `front-lembretes-fidelidade` | Feito em A6 | `WebPagesTest::test_clients_page_has_the_loyalty_column_for_the_staff` e `test_payment_modal_offers_the_loyalty_reward` |
| `front-a11y-basica` | Feito em A2 | `resources/views/layouts/app.blade.php` ("Pular para o conteúdo") e `resources/views/partials/form-field.blade.php` |
| `front-responsivo-390` | Feito em A2; conferido nos prints da F; a lista dos cadastros no celular entrou na rodada de acabamento (seção 8) | `docs/prints-telas/*-390.png` (nenhum com rolagem lateral) e `resources/views/partials/crud.blade.php` |
| `front-estados-erro` | Feito em A2 (o Agendar não fica mais em "Carregando...") e em A3 (401, 403 e 419 no `apiRequest`) | `resources/js/pages.js` |
| `front-campos-planejados` | Fora do escopo, porque a `docs/ARQUITETURA.md` adiou status e comissão do barbeiro (§7 do plano). O vínculo com o login entrou em A3 | `docs/ARQUITETURA.md`, "Diferenças em relação ao esboço inicial" |
| `front-paginacao-exclusao` | Feito em A2 (paginação nas telas) e em A4 (o 409 da exclusão com a mensagem na tela) | `resources/views/partials/crud.blade.php` e `App\Models\Concerns\HasAppointments` |
| `front-codigo-sobrando` (Tailwind, Instrument Sans e `app.js`) | Feito em A3 | `vite.config.js` e `package.json` |

### Nuvem

| Falta | Situação | Prova |
|---|---|---|
| `compose-caminho-codigo` | Feito em N1 | `docker-compose.yml` (monta a própria pasta do repositório) |
| `docker-php-versao` | Feito em N1 (imagem) e em A1 (docs) | `docker/php/Dockerfile` (`php:8.4.27-fpm-bookworm`) |
| `compose-env-app-db` | Feito em N1 | `docker-compose.yml` (o `app` recebe os `DB_*` do bloco `x-mysql-credentials`) |
| `compose-bootstrap-app` | Feito em N1; na produção, sem gerar chave e com as migrations por `RUN_MIGRATIONS` | `docker/php/entrypoint-dev.sh` e `docker/php/entrypoint.sh` |
| `tf-security-groups` | Feito em N3 | `terraform/sg.tf` e `terraform/tests/dados_seguranca.tftest.hcl` (`security_groups`) |
| `tf-ec2-sem-bootstrap` | Feito em N4 (validado só no código) | `terraform/compute.tf` e `terraform/templates/user_data.sh.tftpl` |
| `ci-pipeline-inexistente` | Feito em N5 (validado só no código até o push) | `.github/workflows/ci.yml` |
| `cd-deploy-inexistente` | Feito em N5 e em N3 (ECR); validado só no código | `.github/workflows/deploy.yml`, `terraform/cicd.tf` e `terraform/ecr.tf` |
| `tf-rede-vpc-subnets` | Feito em N2 | `terraform/network.tf` e `terraform/tests/rede.tftest.hcl` |
| `tf-iam-ausente` | Feito em N3 (role das instâncias) e em N5 (role de deploy) | `terraform/iam.tf` e `terraform/cicd.tf` |
| `rds-backup-protecao` | Feito em N3; o teste de restore só na AWS (go-live B8) | `terraform/main.tf` e `docs/nuvem/BACKUP.md` |
| `rds-criptografia-tls` | Feito em N3 (validado só no código) | `terraform/main.tf` (`storage_encrypted` e `require_secure_transport`) e `terraform/ssm.tf` (`MYSQL_ATTR_SSL_CA`) |
| `cache-distribuido-ausente` | Feito em N3 (ElastiCache) e em N1 (Redis no compose e a extensão na imagem) | `terraform/cache.tf` e `docker-compose.homolog.yml` |
| `docker-imagem-artefato` | Feito em N1 | `docker/php/Dockerfile` (estágios `vendor`, `assets` e `app`) e `.dockerignore` |
| `docker-otimizacao-producao` | Feito em N1 | `docker/php/conf.d/otimizado.ini` e `docker/php/entrypoint.sh` (`optimize`) |
| `sprint4-fora-do-repo` | Feito em N6 | `monitoring/`, `load-tests/`, `terraform/monitoring.tf` e `docs/nuvem/sprint4/` |
| `obs-logs-centralizados` | Feito em N6 (Loki), N3 (log groups) e N4 (`awslogs`) | `monitoring/alloy/config.alloy`, `terraform/logs.tf` e seção 4.3 (os 13 serviços no Loki) |
| `obs-metricas-dashboards-alertas` | Feito em N6 | `monitoring/grafana/dashboards/barberflow.json` e `terraform/monitoring.tf` |
| `go-live-producao` | Fora do escopo, porque o `apply` e o go-live na AWS precisam da conta do grupo e custam dinheiro (§7 do plano). No lugar: a homologação local completa (F) e o roteiro | `docs/nuvem/go-live-checklist.md` e `docs/nuvem/TERRAFORM.md`, "Primeiro deploy" |
| `doc-tecnica-nuvem` | Feito de N1 a N6 (guias) e na F (relatório e apresentação) | `docs/nuvem/`, este relatório e `docs/APRESENTACAO.md` |
| `tf-variables-outputs-vazios` | Feito em N2 e em N4 (a AMI pelo parâmetro do SSM do Amazon Linux 2023) | `terraform/variables.tf`, `terraform/outputs.tf` e `terraform/compute.tf` |
| `tf-estado-remoto` | Feito em N2 | `terraform/backend.tf`, `terraform/bootstrap/` e `terraform/.gitignore` |
| `compose-segredos-e-porta-db` (porta 3306 aberta e rede única) | Feito em N1; as senhas ficam (decisão 5) | `docker-compose.yml` (`127.0.0.1:3306` e as redes `frontend` e `backend`) |
| `compose-healthcheck-depends` | Feito em N1 | `docker-compose.yml` (healthcheck em todos os serviços) |
| `rds-versao-mysql` | Feito em N3 (RDS 8.4) e em N1 (`mysql:8.4.13`) | `terraform/main.tf` (`engine_version = "8.4"`) |
| `ha-escalabilidade` | Feito em N4 (ALB e Auto Scaling em 2 zonas) e em N3 (Multi-AZ e réplica do cache na prod); validado só no código | `terraform/compute.tf` e `terraform/tests/computacao.tftest.hcl` |
| `testes-carga-versao-atual` | Feito em N6 (scripts com login) e na F (a rodada com as metas) | `load-tests/barberflow.js` e `load-tests/results/f1-*.json` |
| `tf-versoes-nao-fixadas` | Feito em N2 | `terraform/versions.tf` e `terraform/.terraform.lock.hcl` |

### Qualidade

| Falta | Situação | Prova |
|---|---|---|
| `segredos-versionados` | Fora do escopo, porque a decisão 5 (13/11/2026) manteve as senhas que já existiam e o histórico do Git. As senhas novas ficam fora do Git, e o risco está registrado | `docs/nuvem/SEGURANCA.md`, "Riscos aceitos" |
| `debug-sem-config-producao` | Feito em N1 (bloco de produção) e em N3 (parâmetros do SSM) | `docker-compose.prod.yml` (`APP_DEBUG: "false"` e `LOG_LEVEL: info`) e seção 4.3 (`php artisan about`) |
| `cors-aberto` | Feito em A3 | `config/cors.php` e `SecurityHeadersTest::test_cors_never_opens_the_api_to_an_unlisted_site` |
| `sem-https` | Feito no código em N4 (HTTPS no ALB com `certificate_arn`) e em A3 (proxies confiáveis, cookie seguro e HSTS). HTTPS de verdade fica fora do escopo, porque precisa de um domínio comprado (§7 do plano) | `terraform/alb.tf` e `SecurityHeadersTest::test_hsts_only_on_secure_requests` |
| `headers-seguranca-incompletos` | Feito em A3 (aplicação) e em N1 (`expose_php` e `server_tokens`) | `app/Http/Middleware/SecurityHeaders.php` e seção 4.6 |
| `imagem-docker-sem-hardening` | Feito em N1 | `docker/php/Dockerfile` (`USER www-data`) e `docker-compose.prod.yml` (`read_only` e `no-new-privileges`) |
| `dependencias-com-cve` | Feito em A1 (os 7 alertas do Symfony) e em N5 (`composer audit` no CI). Um alerta novo apareceu depois no `laravel/framework` (seção 7) | `composer.lock` e `.github/workflows/ci.yml` |
| `validacao-sem-limites-do-banco` | Feito em A2 | `ServiceApiTest::test_rejects_values_outside_the_limits` e `ClientApiTest::test_rejects_fields_longer_than_the_columns` |
| `pint-falhando` | Feito em A1 | Seção 4.2 (Pint passou nos 132 arquivos) |
| `testes-js-front` | Fora do escopo, porque teste e lint de JS exigiriam dependência nova, e nenhuma das réguas pede (§7 do plano) | |
| `testes-resiliencia` | Feito em N6 (roteiro) e na F (o `/api/health` em 503 e 200; na rodada de acabamento, também o banco congelado e o servidor de e-mail fora; na segunda, o servidor de e-mail congelado) | `load-tests/resiliencia/README.md` |
| `readme-desatualizado` | Feito em A1 e reescrito na F | `README.md` |
| `agents-md-desatualizado` | Feito em A1 e atualizado na F | `AGENTS.md` |
| `arquitetura-sem-front-e-nuvem` | Feito de A2 a A6 (cada módulo), em N6 (nuvem) e na F (telas e topologia) | `docs/ARQUITETURA.md` e `docs/nuvem/ARQUITETURA-NUVEM.md` |
| `diretrizes-seguranca-ausentes` | Feito em N6 e completado na F (o checklist OWASP) | `docs/nuvem/SEGURANCA.md` |

### Faltas novas da revisão crítica

| Falta | Situação | Prova |
|---|---|---|
| `microsservicos-vs-monolito` | Feito na F | `docs/adr/0001-monolito-modular.md` |
| `protecao-branch-main` | Fora do escopo, porque a configuração do GitHub é do grupo e o repositório é do José (§7 do plano). O passo a passo está pronto | `docs/nuvem/CI-CD.md`, "3. Proteção da main" |
| `seed-demo-e-admin` | Feito em A3 (`AdminUserSeeder`) e na F (`DemoSeeder`) | `database/seeders/DemoSeeder.php` e `DemoSeederTest` |
| `sanctum-tokens-sem-expiracao` | Feito em A3 (validade, prefixo e a limpeza diária) e em N1 (o container `scheduler`) | `config/sanctum.php:56` e `:71`, `routes/console.php:17` e `AuthApiTest::test_token_expires_after_480_minutes` |
| `health-check-prontidao` | Feito em A2 | `app/Http/Controllers/HealthController.php` e `HealthApiTest` |
| `cadastro-conta-e-recuperacao-senha` | Feito em A3 | `RegistrationTest` e `PasswordResetTest` |
| `lgpd-dados-pessoais` | Feito em A2 (anonimização), A6 (lembrete sem dado pessoal), N6 (retenção e dados guardados) e F (erro de banco no log sem o SQL e falha no envio de e-mail no log sem o endereço). O aviso de privacidade nas telas não foi feito e ficou registrado | `Client::anonymizeAndDelete()`, `DatabaseErrorLogTest`, `MailErrorLogTest` e `docs/nuvem/SEGURANCA.md`, "LGPD" |
| `custos-aws` | Feito em N2 (`default_tags`), N4 (`budget.tf`) e N6 (estimativa) | `docs/nuvem/CUSTOS.md` |
| `testes-seguranca-owasp` | Feito em A3 (testes de 401, 403 e 429) e na F (ZAP baseline e o checklist OWASP) | `docs/nuvem/SEGURANCA.md`, "OWASP API Security Top 10" |
| `openapi-spec` | Fora do escopo, porque OpenAPI não está em nenhuma das duas réguas (§7 do plano). O inventário é o `docs/API.md` | |
| `versionamento-api` | Fora do escopo, pelo mesmo motivo (§7 do plano) | |
| `relatorios-pos-mvp` | Fora do escopo: relatórios avançados, app mobile, multiempresa, WhatsApp e pagamento online são evolução (§7 do plano) | `docs/ARQUITETURA.md`, "Evolução futura", e o ADR 0001 |
| `nginx-gzip-cache-assets` | Feito em N1 | `docker/nginx/default.conf` e seção 4.6 (gzip e cache de um ano no `/build`) |

### Nota: duplicados e refutados

Não viraram linha, porque o verificador ou a revisão crítica os juntaram a outra falta (resolvida junto com ela) ou os refutaram:

- **Refutado:** `compose-worker-fila`. Sem jobs, notificações nem e-mails em fila no código, um worker de fila não era requisito. O lembrete saiu síncrono no agendador (decisão do plano), e o compose ganhou o `scheduler`, não um worker.
- **Juntados a outra falta da API:** `appointments-end-time` (em `appointments-recurso`) e `docs-projeto-desatualizadas` (em `readme-desatualizado`, `agents-md-desatualizado` e `arquitetura-sem-front-e-nuvem`).
- **Juntados a outra falta do front:** `front-rotas-nome-duplicado` (em `colisao-nomes-rotas`), `front-build-no-docker` (em `docker-imagem-artefato`), `front-docs-desatualizadas` (nos três de docs) e `front-agendar-aviso-duplicado` (em `front-agendar-post-conflito` e `front-login-logout-real`).
- **Juntados a outra falta de nuvem:** `tf-segredo-no-codigo` (em `segredos-versionados`) e `nginx-https-hardening` (em `sem-https`).
- **Juntados a outra falta de qualidade:** `api-sem-autenticacao` (em `auth-sanctum-login`, `perfis-permissoes` e `front-protecao-rotas-web`), `tfstate-sem-protecao` (em `tf-estado-remoto`), `api-sem-rate-limiting` (em `rate-limit-api`), `rds-ec2-sem-sg-iam-criptografia-backup` (em `tf-security-groups`, `tf-iam-ausente`, `rds-criptografia-tls` e `rds-backup-protecao`), `rotas-nome-duplicado-route-cache` (em `colisao-nomes-rotas`), `fix-stack-local-nao-publicada` (em `merge-fix-stack-local`), `ci-quality-gate` (em `ci-pipeline-inexistente`), `testes-telas-web` (em `front-testes-rotas-web`), `testes-regras-futuras` (em `testes-novos-recursos`), `testes-carga-estresse` (em `testes-carga-versao-atual`), `monitoramento-metricas` (em `obs-metricas-dashboards-alertas`), `logs-centralizados` (em `obs-logs-centralizados`), `listagens-sem-paginacao` (em `paginacao-listagens`), `mensagens-validacao-em-ingles` (em `validacao-pt-br`), `timezone-utc` (em `timezone-agenda`), `contrato-price-inconsistente` (em `form-requests-api-resources`) e `exclusao-definitiva-sem-historico` (em `soft-delete-historico`).

São 26 itens: 25 duplicados e 1 refutado. Com as 96 linhas acima, fecham os 122 itens das listas.

## 6. Fora do escopo

Do §7 do plano:

| Item | Por quê |
|---|---|
| `terraform apply` e o go-live na AWS | Precisa da conta do grupo e custa dinheiro. No lugar: o roteiro e a homologação local completa |
| O que só a AWS prova: OIDC, deploy, instance refresh, HTTPS no ALB, TLS do RDS e do ElastiCache, IAM mínimo, `awslogs` e o CI verde no GitHub | Validado só no código (`validate`, `test` com o provider simulado e actionlint) até o primeiro `apply` e o push |
| Push, pull requests e a proteção da `main` | O push pede OK separado; os pull requests e a configuração do GitHub são do grupo, e o repositório é do José |
| Mudar qualquer senha que já existe ou reescrever o histórico | Decisão 5 (13/11/2026). O risco está no `docs/nuvem/SEGURANCA.md` |
| Separar um microsserviço; relatórios avançados, app mobile, multiempresa, WhatsApp e pagamento online | ADR 0001 e a evolução da `docs/ARQUITETURA.md` |
| OpenAPI, `/api/v1`, Form Requests e API Resources, teste e lint de JS | Fora das duas réguas; o JS pediria dependência nova |
| `commission_rate`, status do barbeiro e `birth_date` e `notes` do cliente | A própria `docs/ARQUITETURA.md` adiou |
| AWS Backup, VPC Flow Logs e a página `/status` do PHP-FPM | Cortes de excesso do plano |
| Domínio e certificado reais | Precisam ser comprados. O Terraform já aceita o `certificate_arn` |

## 7. Pendências

Esperam o OK do Leonam:

- **Atualizar o `laravel/framework`.** O `composer audit` acusa o GHSA-63hm-g96r-2m2q ("Task Output Injection", no processo de concorrência do Laravel), de severidade baixa, na 13.33.0. A correção é a 13.40.0. Até lá, o passo `composer audit` do job `PHP` do CI falha.
- **O volume do banco da homolog.** O `barberflow-homolog_dbdata` do computador do Leonam foi criado com uma senha que se perdeu, e o app recebe `Access denied` com ele. A verificação usou o MySQL num tmpfs, e a revisão final conferiu a queda do banco e o "subir de novo sem perder nada" (go-live A15) num volume novo, o `barberflow-homolog_dbdata-r` (seção 4.10), que ficou no computador com os dados do `DemoSeeder`. Apagar ou trocar o antigo, e apagar o novo se ele não for mais usado, é decisão dele.
- **Um arquivo solto na worktree Nuvem.** Na raiz da outra worktree do Orca (fora deste repositório) ficou um arquivo sem uso. O nome dele não vai aqui, porque tem o formato de uma senha gerada. Apagar espera o OK dele.

## 8. Achados que ficaram registrados

A verificação, os prints, a revisão final e as duas rodadas de acabamento acharam pontos que pedem decisão ou código. Os que continuam em aberto:

| Achado | Onde | O que resolveria |
|---|---|---|
| Com o banco congelado, cada pedido que lê o banco ocupa um processo do PHP-FPM por até cerca de 20 s, e 10 pedidos ao mesmo tempo enchem o pool: até o `/up`, que não lê o banco, esperou 16,79 s. Na AWS, o health check do ALB (`/up`, com 5 s de limite e 3 falhas seguidas) e o Auto Scaling, que usa o health check do ELB, podem dar as instâncias como doentes e trocá-las se o tráfego mantiver o pool cheio | Seção 4.10, `terraform/alb.tf` e `terraform/compute.tf` | Mais processos no pool, um limite de leitura menor ou um `/up` que não espere o pool (decisão de código e de infra) |
| Com o `DB_READ_TIMEOUT`, a leitura que passa do limite fora de uma transação é repetida pelo Laravel numa conexão nova, e a primeira continua rodando no MySQL até o fim: com o banco já lento, uma leitura pesada (como a lista de clientes com a fidelidade) pesa em dobro, e o pedido espera até duas vezes o limite. Achado pela leitura do código, na revisão da rodada de acabamento | `Connection::tryAgainIfCausedByLostConnection` do Laravel e `app/Providers/AppServiceProvider.php` | Um limite do lado do MySQL só nas requisições web (o `max_execution_time` da sessão, um pouco abaixo do `DB_READ_TIMEOUT`), medido num MySQL de verdade |
| Duas contas que confirmam o e-mail no mesmo instante, no MySQL, com e-mails que caem no mesmo intervalo do índice `unique` de `clients.email`, travam uma à outra: as duas leituras com trava pegam o intervalo (gap lock), e o MySQL derruba uma por deadlock (`1213`). Essa confirmação responde 500, e a conta fica com o e-mail confirmado e sem cadastro de cliente (o Laravel não dispara a confirmação de novo), sem conseguir agendar (403). Provado com duas sessões de verdade num MySQL 8.4 descartável, na segunda rodada de acabamento; no SQLite local, as transações `IMMEDIATE` fazem uma esperar a outra | `App\Listeners\LinkClientToVerifiedUser` | Repetir a transação no deadlock (`DB::transaction(..., 3)`, como o `ReminderSender`) |
| Na saída do agendador, a linha da rodada do lembrete sai `DONE` mesmo quando ela falha (na homolog, `30s DONE` com 3 lembretes falhando). É do Laravel 13.33.0: o `ScheduleRunCommand` devolve ao componente da linha um booleano, e ele só escreve `FAIL` com o código de falha dele (`TaskResult::Failure`). A falha continua registrada (o código 1 do evento, o `ScheduledTaskFailed` e a linha `ERROR` com as contas), e o mesmo vale para o `sanctum:prune-expired` | Seção 4.11, `vendor/laravel/framework` | Fora do código do projeto: quem acompanha o agendador (homolog, Loki ou CloudWatch) procura a linha `ERROR`, e não o `FAIL` |
| Em 390 px, a tela Minha conta quebra um e-mail longo no meio da palavra (o `text-break`, sem os `<wbr>` que a tela "Confirme seu e-mail" ganhou na segunda rodada de acabamento): um e-mail de 63 caracteres saiu partido em "confir" e "macao", conferido no navegador na segunda rodada de acabamento, com a mesma marcação e o mesmo CSS da tela. Os e-mails da demonstração cabem numa linha | `resources/views/account/show.blade.php` | A mesma quebra das tabelas: depois do @ e antes de cada ponto |
| Com o banco inalcançável, o `/api/health` leva 4 vezes o `DB_CONNECT_TIMEOUT` (12 s com 3), porque o Laravel tenta de novo | Seção 4.5 | Nada urgente: fica abaixo dos 60 s do Nginx |
| O campo `file` do log de erro de banco aponta o `app/Http/Middleware/SecurityHeaders.php:28` quando a consulta nasce no vendor (o guard do Sanctum) | `bootstrap/app.php` | Não vaza nada; só pode confundir quem investiga |
| O POST da mesma origem passa sem o token de CSRF, pelo `Sec-Fetch-Site` | Seção 4.6 | É o comportamento do Laravel 13, não uma brecha; ficou documentado |
| O ZAP rodou sem login e só viu as telas abertas | Seção 4.7 | Uma varredura autenticada (com a sessão do admin) numa próxima rodada |
| O HSTS em HTTPS não deu para ver ao vivo | Seção 4.6 | Conferir na AWS, com domínio e certificado (go-live B3) |

Os três detalhes visuais que os prints mostraram foram corrigidos depois, na rodada de acabamento (15/11/2026), conferidos no navegador do Orca, no servidor local, como admin, barbeiro e cliente (o menu em 992, 1024, 1200, 1280 e 1440 px, os cadastros em 390, 768 e 1280 px e a Agenda em 1280 e 390 px), e os prints que eles mudaram foram refeitos na homolog (seção 4.9):

| Achado | O que mudou | Prova |
|---|---|---|
| Em 390 px, as tabelas de Clientes, Barbeiros e Serviços partiam nome e e-mail no meio da palavra | Abaixo de 768 px, os cadastros saem numa lista no lugar da tabela, como Pagamentos: o nome em cima, cada dado com o nome da coluna embaixo dele e os botões no fim. O e-mail quebra só depois do @ e antes de cada ponto, na lista e na tabela | `WebPagesTest::test_crud_page_has_a_list_for_the_phone_and_the_table_from_the_tablet_up` |
| No menu do admin em 1280 px, com um nome longo, o Sair descia para uma segunda linha | O menu abre a partir de 1200 px e, aberto, fica numa linha só: os links não quebram, e quem encolhe é o nome, cortado com reticências e inteiro no `title` e para o leitor de tela | `WebPagesTest::test_menu_fits_in_one_line_and_keeps_the_full_name` |
| O bloco de 20 minutos da Agenda cortava a linha do serviço pela metade | O bloco mostra só as linhas que cabem inteiras na altura dele: num de 15 ou 20 minutos, o início vai na linha do cliente. Se o navegador aumentar a fonte, a linha que não cabe some inteira, sem aparecer cortada | Conferido no navegador, com blocos de 15, 20, 30 e 45 minutos (o projeto não tem teste de JavaScript, seção 6) |

Na mesma rodada, os três achados do servidor também foram corrigidos, com testes:

| Achado | O que mudou | Prova |
|---|---|---|
| Dois cadastros com o mesmo e-mail ao mesmo tempo (clientes, barbeiros e o login deles, ou conta de cliente) passavam os dois pela validação, e o segundo recebia 500 do índice `unique` do banco | O `EmailTakenException::guard()` troca a recusa do índice `unique` de e-mail pelo mesmo 422 da regra ("Este e-mail já está cadastrado.", no campo `email`), como o `PaymentRecorder` faz com o pagamento repetido. A recusa de outro índice continua sendo erro. Nada fica gravado em dobro | `ClientApiTest`, `BarberApiTest` e `RegistrationTest`, com o `beforeQuery()` gravando o mesmo e-mail logo antes da gravação (`test_two_requests_with_the_same_email_at_the_same_time_get_the_same_422` e os vizinhos), e `ClientApiTest::test_a_refusal_of_another_unique_index_is_not_reported_as_the_email`. O `DatabaseErrorLogTest` passou a provocar o 500 por uma chave estrangeira |
| Com o servidor de e-mail fora do ar, o cadastro de conta respondia 500 com a conta já criada, e o reenvio do link também | O cadastro responde como feito (201 na API; nas telas, o cliente entra), com o aviso de que o link não saiu e de que dá para pedir outro em alguns minutos. O reenvio responde 503 na API e volta à tela com o aviso. A falha continua no log sem o endereço | `MailErrorLogTest` (`test_registration_with_the_email_down_creates_the_account_and_warns` e os vizinhos), com o `failMailDelivery()`. Na homolog, com o Mailpit parado: 201 com o aviso no cadastro, 503 no reenvio e 5 linhas no log sem nenhum endereço (seção 4.10) |
| Com o MySQL de pé e sem responder (congelado), a requisição ficava presa até o 504 do Nginx, em 60 s, e o processo do PHP-FPM, até o banco voltar | Limite de cada leitura do MySQL só nas requisições web: o `DB_READ_TIMEOUT` (padrão 5 s), que o `AppServiceProvider` aplica como o `mysqlnd.net_read_timeout`. Com as 4 tentativas do Laravel, o pedido desiste em até 20 s. A linha de comando (migrations, agendador e seeders) fica sem o limite | `ConnectionTimeoutTest` (requisição web e linha de comando). Num PHP 8.4.27 descartável, contra um MySQL falso que aceita a conexão e nunca responde: o `select 1` do `/api/health` desistiu em 20,02 s com o padrão e em 8,01 s com 2 s, sempre com `2006 MySQL server has gone away`, e na linha de comando continuou esperando. Num MySQL 8.4.13 com um ano sintético (2.000 clientes, 28.170 agendamentos e 19.846 pagamentos), a consulta mais lenta do Dashboard de um ano levou 51 ms, e a mais lenta de todas, a da lista de clientes com a fidelidade, 730 ms. Na homolog, com o banco congelado: 503 em 20,05 s no `/api/health`, 500 em 20,03 s na tela e na API e o PHP-FPM livre de novo, sem esperar o banco (seção 4.10) |

Na segunda rodada de acabamento (15/11/2026), três dos achados que a primeira deixou em aberto foram corrigidos, o quarto ficou provado sem erro e a revisão dela achou e corrigiu um quinto, o e-mail só parecido na confirmação (o detalhe e as medições estão na seção 4.11):

| Achado | O que mudou | Prova |
|---|---|---|
| O envio de e-mail não tinha limite de tempo: com o servidor de e-mail congelado, o reenvio do link levou 60,03 s, empatado com os 60 s do Nginx, e a rodada do lembrete esperou 55 s | O `MAIL_TIMEOUT` (`config/mail.php`, padrão 10 s): o envio desiste em 10 s, ou em até 20 s se o servidor congelar no meio da conversa | `ConnectionTimeoutTest`. Na homolog, com o Mailpit congelado: reenvio em 503 em 10,04 s, cadastro em 201 em 10,42 s, "Enviar lembrete agora" em 503 em 10,06 s e a rodada do lembrete com 10 s por lembrete |
| O log do lembrete que falhava se perdia na saída do agendador (`/dev/null`) | A rodada roda dentro do processo do agendador (`Schedule::call`), e o log dela sai no do container `scheduler` | `ReminderCommandTest`. Na homolog, a rodada com o Mailpit congelado deixou 3 avisos só com o `appointment_id` e 1 `ERROR` só com as contas, e a seguinte mandou os 3 lembretes |
| Em 390 px, a tela "Confirme seu e-mail" quebrava um e-mail longo no meio da palavra | Um `<wbr>` depois do @ e antes de cada ponto, com cada pedaço escapado | `WebPagesTest` e o navegador do Orca em 390 px |
| A confirmação do e-mail no mesmo instante do cadastro de cliente da barbearia com o mesmo e-mail daria 500 | Nada: a trava da confirmação faz um pedido esperar o outro, e a barbearia recebe o 422 de e-mail em uso ou a conta é ligada ao cadastro novo | `EmailVerificationConcurrencyTest` (só no MySQL) e duas sessões de verdade num MySQL 8.4 descartável |
| No MySQL, que compara sem diferenciar acento, a confirmação de `maria@gmáil.com` (outro domínio) ligava a conta ao cadastro de cliente de `maria@gmail.com` | A confirmação só liga com o mesmo e-mail, sem contar maiúsculas e minúsculas, e o cadastro de conta recusa e-mail com acento | `RegistrationTest`, com a comparação do MySQL imitada no SQLite e a de verdade no MySQL 8.4 descartável |
