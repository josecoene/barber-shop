# ADR 0001: monólito modular, e não microsserviços

- **Status:** aceita. A escolha do monólito modular está no `docs/ARQUITETURA.md` (§1) desde o esboço inicial do grupo. Este registro, de 15/11/2026 (entrega final, `feat/entrega-final`), explica o porquê e diz quando separar um serviço.
- **Envolve:** as duas disciplinas do projeto. A de APIs se chama "Arquitetura de Microsserviços e Desenvolvimento de APIs", e o `Sistema Cloud.pdf`, da de Computação em Nuvem, fala em microsserviços.

## Contexto

Os dois documentos do projeto dizem coisas diferentes:

| Documento | O que diz |
|---|---|
| `Sistema Cloud.pdf`, §1 (linhas 17 e 18 do `pdftotext -layout`) | "arquitetura moderna baseada em microsserviços conteinerizados (Docker)" |
| `docs/ARQUITETURA.md`, §1 | "API REST monolítica modular": um único serviço com camadas bem separadas |
| `README.md` e `AGENTS.md` | o nome da disciplina de APIs, "Arquitetura de Microsserviços e Desenvolvimento de APIs" |

A auditoria de 12/11/2026 apontou a diferença (falta `microsservicos-vs-monolito`) e pediu uma de duas saídas: registrar a decisão num ADR ou planejar a separação de um serviço. O plano da entrega final escolheu o ADR (§7 do plano: separar um microsserviço fica fora do escopo).

O que pesa na escolha:

- **O tamanho.** Uma barbearia, um time de 4 pessoas e 4 sprints por disciplina. A régua de APIs pede recursos, regras e testes; a de nuvem pede containers, IaC, CI/CD e observabilidade. Nenhuma das duas pede serviços separados com bancos separados.
- **As regras que se cruzam.** Agenda, pagamento e fidelidade dependem uns dos outros na mesma transação:
  - agendar trava o barbeiro, o cliente e o serviço, sempre nessa ordem, antes de procurar sobreposição (`App\Services\AppointmentScheduler`);
  - registrar um pagamento trava o cliente e o agendamento antes de conferir o status, o pagamento que já existe e os prêmios da fidelidade (`App\Services\PaymentRecorder` e `App\Services\LoyaltyService`);
  - a ordem global das travas é barbeiro, cliente, serviço, agendamento e pagamento, e ela é o que evita o deadlock (`docs/ARQUITETURA.md`, "Concorrência").
- **A prova que já existe.** O `AppointmentConcurrencyTest`, o `PaymentConcurrencyTest` e o `LoyaltyConcurrencyTest` conferem as travas no MySQL 8.4, e o `load-tests/concorrencia.js` mandou 50 pedidos iguais ao mesmo tempo na homolog: 1 criado e 49 recusados com 422 (15/11/2026, `load-tests/results/f1-50vus-concorrencia.json`).

## Decisão

**Um serviço só, dividido em módulos por domínio.** Um código Laravel, uma imagem `app`, um banco MySQL e um cache Redis (Valkey na AWS). Os módulos são pastas e classes, não processos:

| Módulo | Onde fica a regra |
|---|---|
| Cadastros (serviços, clientes e barbeiros) | controllers, models e os traits `HasAppointments` e `ReleasesEmailOnDelete` |
| Conta e acesso | `app/Services/Auth`, `app/Policies` e os Gates do `AppServiceProvider` |
| Agenda | `App\Services\AppointmentScheduler` e o enum `AppointmentStatus` |
| Pagamentos | `App\Services\PaymentRecorder` e os enums `PaymentStatus` e `PaymentMethod` |
| Fidelidade | `App\Services\LoyaltyService` |
| Lembretes | `App\Services\ReminderSender`, o `App\Mail\AppointmentReminder` e o comando `appointments:send-reminders` |
| Dashboard | `App\Services\DashboardMetrics` |

As fronteiras são de código, não de rede. Os módulos dividem o banco e se chamam direto, dentro da mesma transação quando a regra pede (o pagamento lê e trava o agendamento; a exclusão do cliente apaga as observações dos agendamentos e dos pagamentos dele).

**Conteinerizado, sim.** O mesmo código roda em três containers com papéis diferentes: `web` (Nginx), `app` (PHP-FPM) e `scheduler` (agendador). Na AWS eles sobem juntos em cada instância do Auto Scaling, atrás do ALB, e o banco e o cache são serviços gerenciados (RDS e ElastiCache). Isso atende ao "conteinerizado", à escalabilidade e à alta disponibilidade do PDF sem dividir o domínio (`docs/nuvem/ARQUITETURA-NUVEM.md`).

## Alternativas que ficaram de fora

1. **Microsserviços desde o começo** (agenda, pagamentos e notificações separados, cada um com o seu banco). Ficou de fora porque:
   - a regra "um pagamento por atendimento concluído" e o prêmio da fidelidade dependem de travar o cliente e o agendamento na mesma transação. Com dois bancos, isso vira uma saga com compensação, e a garantia que o `concorrencia.js` prova (1 de 50) fica bem mais difícil de manter;
   - cada serviço pede imagem, pipeline, deploy, monitoramento e rastreio de chamadas entre serviços próprios. Para 4 pessoas em 4 semanas, o tempo sairia das regras e dos testes;
   - na AWS, mais containers e mais balanceamento custam mais (a homolog de hoje fica perto de US$ 62 por mês, `docs/nuvem/CUSTOS.md`).
2. **Monólito sem módulos**, com a regra dentro dos controllers. Ficou de fora porque as telas e a API repetiriam as mesmas regras, e os testes de concorrência não teriam um lugar só para conferir.

## Consequências

**O que ganha:**

- Uma transação garante as regras que importam (sem sobreposição, um pagamento por atendimento, o prêmio usado uma vez só), provadas no SQLite (735 testes), no MySQL (742) e com pedidos simultâneos de verdade no k6.
- Um deploy só: duas imagens (`app` e `web`), um pipeline (`.github/workflows/ci.yml` e `deploy.yml`), um banco com backup e restore documentados (`docs/nuvem/BACKUP.md`).
- Os testes de feature rodam a aplicação inteira, de ponta a ponta, sem simular outros serviços.

**O que perde:**

- Tudo escala junto. Um relatório pesado no dashboard usa os mesmos processos do PHP-FPM que a agenda (10 por container, `docker/php/php-fpm.d/zz-barberflow.conf`).
- Um deploy troca tudo, e um erro grave num módulo derruba a aplicação inteira.
- O time inteiro mexe no mesmo repositório. O `routes/api.php` é o arquivo com mais conflito de merge (`AGENTS.md`, "Regras").
- O discurso do PDF não bate com o código. Na apresentação, o grupo explica a escolha com este ADR (`docs/APRESENTACAO.md`).

## Quando separar um serviço

Sinais que fazem valer a pena separar, com o candidato de cada um:

| Sinal | Candidato | Como separar |
|---|---|---|
| Lembrete por WhatsApp ou SMS (`docs/ARQUITETURA.md`, "Evolução futura"), volume que atrase a rodada de 15 minutos ou um provedor que falhe muito e precise de fila com nova tentativa | **Notificações**, o primeiro da fila | O comando já reserva a linha e o e-mail leva só o id do agendamento (`X-Appointment-Id`). O serviço novo recebe um evento "lembrete devido" com o id, por uma fila (SQS na AWS), e busca o resto pela API |
| Relatórios pela data do pagamento ou de vários meses pesando no banco da agenda (p95 da carga subindo com o dashboard aberto) | **Relatórios** | Antes de um serviço, uma réplica de leitura do RDS para o `DashboardMetrics`. Depois, um serviço de relatórios alimentado por eventos de agendamento e de pagamento |
| Pagamento online, com gateway e webhooks | **Integração de pagamento** | O serviço novo fala com o gateway e confere os webhooks. O registro do pagamento continua no monólito, na mesma transação da agenda, enquanto a regra de um pagamento por atendimento depender da trava |
| Várias barbearias na mesma plataforma (SaaS multiempresa) | Nenhum módulo: a divisão é por empresa | Banco por empresa ou coluna de empresa em todas as tabelas, decidido antes de qualquer separação por domínio |
| Equipes separadas cuidando de partes diferentes, com deploys que esperam um pelo outro | O módulo da equipe que mais espera | O mesmo caminho do primeiro sinal: rota própria atrás do ALB (regra por caminho), contrato no `docs/API.md` e banco próprio |

**O que não se separa enquanto as regras forem as de hoje:** agenda, pagamentos e fidelidade. As três dependem das mesmas travas, na mesma transação.

**Como separar sem reescrever:** um módulo de cada vez, com o serviço novo atrás do mesmo ALB, o contrato que o `docs/API.md` já descreve e o teste de carga (`load-tests/`) rodando antes e depois, para provar que a separação não piorou o tempo de resposta.

## Referências

- `docs/ARQUITETURA.md`: §1 (visão geral), "Agenda" e "Pagamentos" (concorrência) e "Topologia".
- `docs/nuvem/ARQUITETURA-NUVEM.md`: o desenho na AWS.
- `Sistema Cloud.pdf`, na raiz do repositório: §1 e §2.
- `load-tests/README.md`, "Concorrência da agenda".
