# Apresentação do BarberFlow

Roteiro da demonstração e slides da apresentação final, para as duas disciplinas (APIs e Computação em Nuvem). A demonstração roda na homolog local, com as imagens de produção e os dados do `DemoSeeder`. Os números dos slides são os da verificação de 14 e 15/11/2026 ([relatório técnico](RELATORIO-TECNICO.md), seção 4).

- **Tempo:** 20 minutos de apresentação e o resto para perguntas. O `Sistema Cloud.pdf` não fixa o tempo: ajuste os blocos se o professor der outro limite.
- **Quem apresenta:** decisão do grupo. A sugestão da tabela segue os papéis do `Sistema Cloud.pdf` (Manoel na nuvem e no Terraform, Eduardo nos dados e na segurança de acesso, José nos containers e no CI, Leonam na segurança, no monitoramento e nos testes).

## Antes de apresentar

**No dia anterior:**

1. Atualize o computador da demonstração na branch da entrega (`feat/entrega-final`, ou a `main` depois do merge) e faça o build das imagens (`docs/nuvem/DOCKER.md`, "Subir", passos 1 e 2).
2. Teste o roteiro inteiro uma vez, com o cronômetro.
3. Separe o plano B: os prints de [`docs/prints-telas/`](prints-telas/README.md) e as tabelas do relatório, abertos num navegador fora da homolog. Se o Docker ou a rede falharem, a demonstração segue pelos prints.

**No dia, até 2 horas antes** (a sessão das telas vale 120 minutos):

1. Suba a homolog com o monitoramento: `homologmon up -d --build --wait` (`docs/nuvem/MONITORAMENTO.md`, "Subir"). Se o `app` der `Access denied for user 'barberflow'`, o banco da homolog foi criado com outra senha: veja `docs/nuvem/DOCKER.md`, "Problemas comuns".
2. Crie o admin e os dados de demonstração: `docs/nuvem/DOCKER.md`, "Dados de demonstração e administrador". **A agenda fica em volta do dia em que o seeder roda** (15 dias antes, o dia e 14 depois). Com um banco que já tinha a demonstração de outro dia, a agenda continua a daquele dia, e o "hoje" pode aparecer vazio. Um banco novo apaga os dados da homolog: combine com o grupo antes. No computador do Leonam, o banco da homolog (o volume `barberflow-homolog_dbdata`, criado em 15/11/2026) já tem o admin e a demonstração, com a agenda de 31/10 a 28/11/2026: os seeders não mudam nada nele. Ele tem também o resíduo de um teste de carga (seção 7 do relatório), que aparece na demonstração enquanto não for tirado: em novembro, o Dashboard conta um 4º barbeiro, de teste e já excluído, na Ocupação do mês (a tela mostra "(4 barbeiros)", e a taxa sai um quarto mais baixa que a dos 3 barbeiros da demonstração) e no resumo por barbeiro, zerado, e o total de Atendimentos e o Cancelado ganham 1; a Agenda de 16/11 mostra o agendamento cancelado desse barbeiro em "Cancelados e faltas" (no celular, numa seção dele). Ao mostrar a ocupação do mês no bloco 3, avise que ela conta esse barbeiro de teste.
3. Confira o `/api/health` (`curl.exe -i http://localhost:8080/api/health`, com banco e cache `ok`).
4. O painel do Grafana mostra o tráfego dos últimos minutos, e a própria demonstração das telas (bloco 3) já gera o que o bloco 5 mostra. **Não rode o smoke do k6 no banco da demonstração só para encher o gráfico.** Ele cadastra um cliente por iteração (29 `Cliente k6 ...` em 30 segundos, na rodada do Laravel 13.40.0), que o `teardown()` não apaga e que aparecem na tela Clientes e na lista de clientes do Agendar. E, com o admin da apresentação como usuário de carga, as telas dele recebem `429` ("Muitas requisições") por até um minuto depois da volta aos limites normais, porque o contador do limite por usuário fica no Redis com os pedidos do teste (`load-tests/README.md`, "Problemas comuns"). Se rodar mesmo assim (`load-tests/README.md`, com a volta pela `homologmon up -d --wait`, que recria o `app` e o `web` sem mexer no banco), use um usuário de carga que não seja o admin da apresentação, exclua depois os `Cliente k6` como admin (na tela Clientes, buscando por `Cliente k6`, ou pelo `DELETE /api/clients/{id}`, como na seção 4.12 do relatório; a exclusão anonimiza) e espere um minuto antes do passo 5.
5. Deixe três janelas logadas, uma por perfil. Os cookies são por endereço, então cada perfil precisa de uma janela separada: a normal (admin), uma anônima (barbeiro) e outro navegador (cliente).
6. Deixe abertas as abas do Grafana (http://localhost:3000), do Mailpit (http://localhost:8025) e dos alvos do Prometheus (http://localhost:9090/targets), o Postman e um terminal do PowerShell na raiz do repositório.

**Logins** (lista completa no `docs/nuvem/DOCKER.md`):

| Perfil | E-mail | Senha |
|---|---|---|
| Admin | `admin@demo.barberflow.test` (o `HOMOLOG_ADMIN_EMAIL`) | `HOMOLOG_ADMIN_PASSWORD` |
| Barbeiro | `carlos.mendes@demo.barberflow.test` | `HOMOLOG_DEMO_USER_PASSWORD` |
| Cliente | `ana.ribeiro@demo.barberflow.test` (a que tem o prêmio de fidelidade) | `HOMOLOG_DEMO_USER_PASSWORD` |

**Senha nunca na tela.** As senhas ficam no `docker/homolog/homolog.env`, que não pode aparecer no projetor. Para entrar, copie a senha para a área de transferência e cole no campo (`Get-HomologValue 'HOMOLOG_DEMO_USER_PASSWORD' | Set-Clipboard`, com a função do `docs/nuvem/DOCKER.md`). No fim, limpe a área de transferência (`Set-Clipboard -Value ' '`).

**No Postman:** troque o `baseUrl` da coleção para `http://localhost:8080/api` e o `loginEmail` para o e-mail do admin. Ponha a senha do admin no `loginPassword` só como "Current value" (o "Initial value" vai junto quando a coleção é exportada).

## Roteiro da demonstração

| # | Tempo | Bloco | Sugestão de quem fala | Slides |
|---|---|---|---|---|
| 1 | 0:00 a 2:00 | O problema e a solução | Leonam | 1 a 3 |
| 2 | 2:00 a 4:00 | Arquitetura: monólito modular, containers e a regra da agenda | Manoel | 4 a 6 |
| 3 | 4:00 a 11:00 | Demonstração das telas: cliente, barbeiro e admin | Leonam | 7 |
| 4 | 11:00 a 13:00 | A API: token, permissões e segurança | Eduardo | 8 |
| 5 | 13:00 a 17:00 | A nuvem: Terraform, CI/CD e monitoramento | Manoel, José e Eduardo | 9 a 12 |
| 6 | 17:00 a 19:00 | Testes e evidências | Leonam | 13 |
| 7 | 19:00 a 20:00 | O que ficou de fora e perguntas | Leonam | 14 e 15 |

### Bloco 3: as telas (7 minutos)

A ordem importa: a cliente vê o prêmio de fidelidade **antes** de o admin usar o prêmio no pagamento.

1. **Visitante (30 s):** a tela de login, com "Criar conta" e "Esqueci minha senha".
2. **Cliente Ana Paula Ribeiro (2 min), na janela do outro navegador:**
   - Meus horários: o prêmio de fidelidade disponível e a barra de visitas, os próximos horários com o "Cancelar" (até 2 horas antes) e os passados com o status do pagamento.
   - Agendar: barbeiro, serviço e data, e os horários livres viram botões. Pare no resumo, sem confirmar (ou confirme, se quiser mostrar o horário entrando em Meus horários).
3. **Barbeiro Carlos Mendes (1 min 30 s), na janela anônima:**
   - Agenda: só a coluna dele.
   - Pagamentos: só os da agenda dele, com "Marcar como pago" e sem Corrigir ou Estornar.
4. **Admin (3 min), na janela normal:**
   - Dashboard: faturamento, atendimentos, ticket médio, faltas e ocupação do mês, e as barras por dia e por forma.
   - Agenda do dia: uma coluna por barbeiro. Abra o detalhe de um agendamento ainda não começado e clique em "Enviar lembrete agora"; mostre o e-mail chegando no Mailpit, com o horário e os dados da barbearia e sem o nome, o e-mail, o telefone e as observações do cliente no corpo.
   - Clientes: a coluna Fidelidade (como 6/10) e o selo "Prêmio disponível" na Ana Paula Ribeiro.
   - Pagamentos: o "Registrar pagamento" do atendimento da Ana Paula Ribeiro, com o aviso do prêmio. Marque "Usar o prêmio de fidelidade": o valor trava em 0,00. **Isso grava no banco e só dá para mostrar uma vez por banco.**

Se sobrar tempo, mostre uma tela em 390 px (as ferramentas de desenvolvedor do navegador, modo celular): a Agenda vira uma lista por barbeiro, e Clientes e Pagamentos viram uma lista no lugar da tabela.

### Bloco 4: a API (2 minutos)

No Postman:

1. "Login (guarda o token)": o token sai com o prefixo `barberflow_` e vale 480 minutos.
2. "Listar (appointments)": a agenda paginada, com `data`, `links` e `meta`.
3. Tire o token (ou use um vencido) e repita: `401`, "Não autenticado.".
4. Faça o login como a cliente e chame "Listar clients": `403`, "Você não tem permissão para esta ação.".

No terminal: `curl.exe -I http://localhost:8080/login`, com os headers de segurança e sem `X-Powered-By`.

### Bloco 5: a nuvem (4 minutos)

1. **Desenho (Manoel):** o diagrama do slide 9 (o mesmo do `docs/nuvem/ARQUITETURA-NUVEM.md`): ALB, Auto Scaling em 2 zonas, RDS e ElastiCache nas subnets privadas.
2. **Terraform (Manoel e Eduardo):** `tf test` (com a função `tf` do `docs/nuvem/TERRAFORM.md`), que termina com `Success! 69 passed, 0 failed.` sem conta da AWS. Ele leva perto de 20 segundos; se a rede estiver lenta, mostre a saída já pronta. Os security groups em camadas e a senha do banco no SSM.
3. **CI/CD (José e Manoel):** os jobs do `.github/workflows/ci.yml` e o deploy por tag com OIDC e rollback (slide 11).
4. **Monitoramento (Leonam):** o painel "BarberFlow: visão geral" no Grafana, com o tráfego da demonstração, e os logs de todos os containers no Loki (Explore, `{service="app"}`).

### Bloco 6: testes e evidências (2 minutos)

O slide 13 com os números. Para uma prova ao vivo, a queda do cache (30 segundos):

```powershell
homologmon stop redis
curl.exe -s http://localhost:8080/api/health
homologmon start redis
curl.exe -s http://localhost:8080/api/health
```

O primeiro `/api/health` responde `503` com `"cache":"falhou"` em uns 4 segundos; o segundo, `200`. **Não derrube o banco (`stop db`) na apresentação:** com o MySQL num tmpfs, como na verificação, isso apaga o banco da homolog, e a demonstração some. Com o volume (o padrão da homolog, e o caso do computador do Leonam desde 15/11/2026), os dados ficam e o banco volta em uns 2 segundos depois do `start`, mas, enquanto ele está fora, as telas e a API dão erro (o `/api/health` leva uns 13 segundos para responder `503`).

### Se algo der errado

| Problema | O que fazer |
|---|---|
| A tela pede login de novo | A sessão venceu (120 minutos). Entre de novo, colando a senha |
| `/api/health` em 503 | Veja qual conferência falhou (`database` ou `cache`) e suba o serviço (`homologmon start db` ou `redis`) |
| O "hoje" da Agenda está vazio | O seeder rodou em outro dia. Navegue até uma data com agendamentos ou siga pelos prints |
| O Docker não sobe | Siga pelos prints de `docs/prints-telas/` e pelas tabelas do relatório |

## Slides

Um slide por bloco entre os `---`, em Markdown, para colar na ferramenta de slides do grupo.

---

### 1. BarberFlow

Gestão e agendamento para barbearias

- Arquitetura de Microsserviços e Desenvolvimento de APIs
- Computação em Nuvem
- Eduardo Romano, Jose Coene, Leonam e Manoel

---

### 2. O problema

- Horários vazios e faltas
- Cliente que não volta
- Faturamento sem acompanhamento
- A agenda no papel ou no WhatsApp, sem regra contra dois clientes no mesmo horário

---

### 3. A solução

| Perfil | O que faz |
|---|---|
| Admin | Cadastros, a agenda de todos, pagamentos, lembretes e o Dashboard |
| Barbeiro | A própria agenda, os pagamentos dela e os clientes |
| Cliente | Agenda sozinho, cancela até 2 h antes e acompanha a fidelidade |

- Lembrete por e-mail 24 h antes
- A cada 10 atendimentos, o seguinte sai grátis

---

### 4. Arquitetura: monólito modular

- Um serviço Laravel 13 (PHP 8.4), uma imagem, um banco MySQL 8.4 e um cache Redis (Valkey na AWS)
- Módulos por domínio, cada um com a regra numa classe: agenda, pagamentos, fidelidade, lembretes e dashboard
- Três containers com papéis diferentes: `web` (Nginx), `app` (PHP-FPM) e `scheduler`
- Por que não microsserviços: agenda, pagamento e fidelidade dependem da mesma transação (ADR 0001)
- Quando separar: notificações por WhatsApp, relatórios pesados e pagamento online

---

### 5. O caminho de um pedido

Navegador ou Postman, depois o Nginx, o PHP-FPM, os middlewares (headers, limite, sessão, CSRF e login), a Policy do perfil, a regra do módulo, e o MySQL e o Redis

- Telas em Blade: buscam os dados na própria API, com a sessão do navegador
- API: token Bearer para o Postman e outros sistemas

---

### 6. A regra que mais importa: agenda sem conflito

- Ninguém em dois lugares ao mesmo tempo: nem o barbeiro, nem o cliente
- Trava das linhas sempre na mesma ordem: barbeiro, cliente e serviço
- Provado no MySQL com duas conexões e com pedidos de verdade:

| Pedidos iguais ao mesmo tempo | Criados | Recusados (422) |
|---|---|---|
| 10 | 1 | 9 |
| 50 | 1 | 49 |

---

### 7. Demonstração

1. Cliente: Meus horários, fidelidade e Agendar
2. Barbeiro: a própria agenda e os pagamentos
3. Admin: Dashboard, Agenda, lembrete no Mailpit e pagamento com o prêmio

---

### 8. Segurança

- Sessão com CSRF nas telas, token com validade e prefixo na API
- Uma Policy por recurso: 401 sem login e 403 para cada perfil, testados em todas as rotas
- Limites por minuto, headers de segurança e CSP sem nada inline
- LGPD: cliente excluído anonimizado, lembrete e log de erro sem dado pessoal
- OWASP API Top 10 com evidência para cada risco; ZAP com 0 FAIL

---

### 9. A nuvem na AWS

- VPC com subnets públicas e privadas em 2 zonas
- ALB, Auto Scaling de 1 a 4 instâncias, IMDSv2 e disco criptografado
- RDS MySQL 8.4 e ElastiCache Valkey nas subnets privadas, com TLS
- Configuração no SSM, imagens no ECR, logs e alarmes no CloudWatch
- Nada aplicado ainda: custa perto de US$ 62 por mês só a homolog

---

### 10. As 4 sprints do PDF

| Sprint | Pedido | Onde está |
|---|---|---|
| 1 | Terraform, containers, compose e Nginx | `docker/`, `docker-compose*.yml` e `terraform/network.tf` |
| 2 | Banco gerenciado, cache e backup | `terraform/main.tf`, `cache.tf`, `sg.tf` e `iam.tf` |
| 3 | Imagens otimizadas e CI/CD | `docker/php/Dockerfile` e `.github/workflows/` |
| 4 | Monitoramento, carga e go-live | `monitoring/`, `load-tests/` e `terraform/monitoring.tf` |

---

### 11. CI/CD

- CI em todo pull request: Pint, testes no SQLite e no MySQL, `composer audit`, `npm audit`, build das imagens com Trivy, Terraform e actionlint
- Deploy por tag `v*`, com aprovação: OIDC (sem chave guardada), push no ECR, instance refresh e conferência do `/api/health`
- Rollback pela tag da imagem, sem build novo
- Actions fixadas pelo SHA e o Dependabot com 7 dias de espera

---

### 12. Observabilidade

- Homolog: Prometheus, Grafana (10 painéis em 3 linhas), Loki e Alloy com os logs de todos os containers
- AWS: 12 alarmes na homolog e 15 na prod, com aviso por e-mail, e o dashboard do CloudWatch
- `/up` raso para o ALB; `/api/health` com banco e cache para o monitoramento e o deploy

---

### 13. Testes e números

| O quê | Resultado |
|---|---|
| Suíte no SQLite | 747 passaram (11 só no MySQL) |
| Suíte no MySQL 8.4 | 758 passaram |
| k6, estresse com 200 VUs | p95 de 17,6 ms, 0% de erro |
| Concorrência da agenda | 1 criado em 50 pedidos iguais |
| Resiliência | Cache fora: 503 em 3 a 4 s e de volta em 200; app de volta em 1,7 s; banco num volume de volta em 1,9 s, sem perder nada; banco congelado: 503 em 20 s (antes, 504 em 60 s); servidor de e-mail congelado: 503 em 10 s (antes, 60 s) |
| OWASP ZAP baseline | 0 FAIL, 4 avisos baixos ou informativos |
| Dependências | `composer audit` e `npm audit` sem nenhum alerta, com o Laravel 13.40.0 |
| Terraform | 69 testes passaram; `trivy config` sem problema |

Os números do k6 valem para o computador da homolog, não para a AWS.

---

### 14. O que ficou de fora

- Aplicar na AWS e o go-live: precisa da conta do grupo e custa dinheiro
- O CI verde no GitHub, os pull requests e a proteção da `main`: depois do push
- Achados em aberto: o pool do PHP-FPM cheio com o banco congelado e a leitura lenta que o Laravel repete (relatório, seção 8)

---

### 15. Perguntas

- Código e docs: `README.md`, `docs/ARQUITETURA.md` e `docs/RELATORIO-TECNICO.md`
- Telas: `docs/prints-telas/`
