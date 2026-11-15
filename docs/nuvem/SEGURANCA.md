# Segurança do BarberFlow na nuvem

Este guia junta, num lugar só, as diretrizes de segurança da infraestrutura e da aplicação, o que o projeto faz pela LGPD, o esqueleto do checklist OWASP API Security Top 10 (preenchido com as evidências na entrega final) e os riscos que o grupo aceitou de propósito, com o que protege cada um.

**Situação:** as diretrizes de infraestrutura estão no código e validadas como o resto da nuvem (`terraform test` com o provider simulado, `trivy config`, a homolog local); nada foi aplicado na AWS. As da aplicação chegam com as branches da API (`feat/api-qualidade` a `feat/lembretes-fidelidade`) e são conferidas na entrega final (`feat/entrega-final`), que preenche o checklist OWASP.

## Diretrizes

### Rede e acesso

- **Cada camada só aceita a da frente:** o ALB recebe 80 e 443 da internet; as instâncias, só a 80 do ALB; o banco, só a 3306 das instâncias; o cache, só a 6379 das instâncias (`terraform/sg.tf`). Banco e cache ficam nas subnets privadas, sem rota para a internet.
- **Nenhuma porta de administração aberta:** sem SSH e sem chave. Quem administra entra pelo Session Manager, com o perfil do grupo e o registro do acesso na AWS (`docs/nuvem/TERRAFORM.md`, "Entrar na instância").
- **Permissão mínima:** a role das instâncias só baixa as 2 imagens do ECR, escreve nos 2 log groups e lê os parâmetros do próprio ambiente, com uma negação explícita dos outros ambientes. A role do deploy só faz push no ECR, grava a `image_tag` e começa o instance refresh. Os testes do Terraform conferem as duas políticas, ação por ação.
- **Deploy sem chave guardada:** o GitHub Actions entra por OIDC, com credencial de 1 hora, só a partir do environment `production` protegido por revisores e restrito à `main` e às tags `v*` (`docs/nuvem/CI-CD.md`).
- **Metadados da instância só para o host:** IMDSv2 obrigatório com hop limit 1. Os containers não alcançam as credenciais da role.

### Segredos

- **Nada de segredo no Git, no `user_data` nem nas imagens:** a senha do banco, o `APP_KEY` e as credenciais do e-mail ficam no SSM como SecureString e chegam à instância na subida. O `APP_KEY` vai pelo atributo write-only `value_wo`, que não fica no state. Os testes conferem que o `user_data` não tem senha nem chave. As exceções que vieram de antes estão em "Riscos aceitos".
- **Homolog local:** a chave e as senhas ficam no `docker/homolog/homolog.env`, gerado com valores aleatórios e ignorado pelo Git e pelo build (`docs/nuvem/DOCKER.md`). A senha do admin do Grafana e a do usuário do exporter chegam só por variável (`${VAR:?}`), sem valor padrão nos arquivos. A senha do root que o MySQL gera e escreve no log quando cria o banco chega ao Loki como `[oculto]`, trocada pelo Alloy (`docs/nuvem/MONITORAMENTO.md`, "Os logs no Loki").
- **Testes de carga:** o e-mail e a senha do usuário de carga passam do terminal para o k6 por variável, nunca por arquivo. O resultado gravado (`load-tests/results/`) não leva os tokens, e o `teardown()` faz o logout de todos.
- **State do Terraform:** guarda valores em texto puro, inclusive a senha do RDS. Por isso o bucket é privado, versionado, criptografado e só aceita HTTPS, e os planos salvos (`*.tfplan`) ficam fora do Git.

### Dados

- **Criptografia em repouso:** disco do RDS, backups e snapshots, disco das instâncias (gp3), cache e imagens do ECR.
- **Criptografia em trânsito:** TLS obrigatório no RDS (`require_secure_transport`, com o bundle de CA da AWS conferido por SHA-256 na imagem) e no ElastiCache (`tls://`). Na internet, HTTPS no ALB quando o grupo tiver domínio e certificado (enquanto não tem, veja "Riscos aceitos").
- **Banco protegido contra perda:** backup de 7 dias com restore de qualquer minuto, snapshot final e proteção contra exclusão na prod (`docs/nuvem/BACKUP.md`).

### Imagens, versões e dependências

- **Versões fixas em tudo:** imagens pela tag exata (as ferramentas do CI e do Terraform e as duas do monitoramento que enxergam o computador, o Alloy e o cAdvisor, também pelo digest), actions do GitHub pelo SHA do commit, provider do Terraform com o lock das quatro plataformas e o compose das instâncias conferido por SHA-256.
- **Varredura:** Trivy nas imagens `app` e `web` em todo pull request e antes de cada deploy (falha em CRITICAL com correção publicada), `trivy config` no Terraform, scan no push do ECR, `composer audit` e `npm audit`. O Dependabot propõe versões novas (inclusive as imagens do Dockerfile e dos compose) com 7 dias de espera, para dar tempo de alguém achar uma versão adulterada.
- **Containers com o mínimo:** a imagem `app` roda como `www-data`, sem compilador e sem o `X-Powered-By`; os containers de produção têm o disco só de leitura e `no-new-privileges`; o Nginx não mostra a versão. Na homolog, os exporters do monitoramento e o k6 ficam em redes só deles e do serviço que leem ou testam, longe do PHP-FPM do `app`, do Redis e do Mailpit.

### Aplicação

- Headers de segurança, CSP, HSTS em HTTPS, CORS restrito ao `APP_URL`, hosts aceitos (`trustHosts`) e proxies confiáveis (`TRUSTED_PROXIES`, a faixa da VPC).
- Login por sessão nas telas (cookie e CSRF) e por token Bearer do Sanctum na API, com validade (`SANCTUM_EXPIRATION`) e prefixo `barberflow_`, que ajuda ferramentas de varredura a achar um token vazado.
- Limites de requisição: toda a API por usuário (ou IP), o login por e-mail e IP e por IP, o cadastro, o "esqueci a senha" e o reenvio do e-mail. Só a homolog de carga levanta os limites (`docker-compose.carga.yml`), e nunca na AWS.
- Permissão por perfil (admin, barbeiro e cliente) em Policies e Gates, com testes de 401 e 403 para cada rota e cada perfil.

### Logs e monitoramento

- **Sem dado pessoal nem segredo no log** (próxima seção).
- Logs dos containers e do banco no CloudWatch por 30 dias; no Loki local, 7 dias.
- Alarmes por e-mail para 5xx, latência, instâncias sem saúde ou abaixo do mínimo, banco e cache (`docs/nuvem/TERRAFORM.md`, "Observabilidade"), e o orçamento mensal da conta (`terraform/budget.tf`).

## LGPD

### Que dado pessoal o sistema guarda

| Onde | Dado pessoal | Quem vê |
|---|---|---|
| `clients` | Nome, e-mail e telefone do cliente | Admin e barbeiro; o cliente, só o próprio cadastro |
| `barbers` | Nome, e-mail e telefone do barbeiro | Admin e barbeiro; o cliente vê só o nome |
| `users` | Nome, e-mail, telefone do cadastro de conta e a senha (só o hash) | Cada um a própria conta |
| `appointments` e `payments` | As observações (`notes`), texto livre | Quem vê o agendamento ou o pagamento |
| Logs | O IP de quem acessou (log de acesso do Nginx) | Quem tem acesso aos log groups da conta e ao Loki local |

### O que o projeto faz

- **Exclusão do cliente apaga os dados pessoais (anonimização):** o nome vira "Cliente removido", o e-mail vira `removido+{id}@barberflow.invalid` e o telefone fica vazio (`Client::anonymizeAndDelete()`). As observações dos agendamentos dele e dos pagamentos desses agendamentos são apagadas, porque são texto livre e podem ter dado pessoal, e não aceitam texto novo depois (`422` em `notes`). O histórico de atendimentos e pagamentos continua, sem identificar a pessoa. O barbeiro excluído mantém só o nome; o e-mail é trocado e o telefone, apagado.
- **Conta ligada ao cadastro só depois de confirmar o e-mail:** ninguém vê o histórico de outra pessoa digitando o e-mail dela.
- **Nada de dado pessoal no log:**
  - o log de acesso do Nginx grava o caminho sem a query string (onde a busca leva nome e e-mail) e troca por `[oculto]` o token dos links de redefinição de senha e de confirmação de e-mail. Em 14/11/2026, um e-mail mandado na busca não chegou ao Loki (`docs/nuvem/MONITORAMENTO.md`);
  - o log de erro do Nginx só grava as falhas graves (`crit`), porque as comuns gravam a requisição inteira (veja "Riscos aceitos");
  - o lembrete por e-mail não grava dado pessoal no log da aplicação (`feat/lembretes-fidelidade`, conferido na entrega final);
  - o usuário `exporter` do monitoramento não lê as tabelas da aplicação, só o status do MySQL;
  - o Mailpit da homolog não grava no log quem mandou nem quem recebeu cada e-mail;
  - os resultados do k6 não levam os tokens.
- **Prazo de guarda:** logs por 30 dias no CloudWatch e 7 dias no Loki local; backups do banco por 7 dias; o snapshot final fica até alguém apagar (apague depois da apresentação, `docs/nuvem/CUSTOS.md`).
- **Acesso:** os dados ficam no banco privado, com TLS, e só a aplicação alcança. Quem administra a AWS entra com o perfil do grupo, e o CloudTrail registra.

### O que ainda falta olhar

- **Mensagens de erro do banco no log:** em 14/11/2026, na homolog, um erro de consulta (a tabela ainda sem migration) apareceu no Loki com o SQL junto, que é o formato padrão das exceções do Laravel. Numa consulta com filtro por nome ou e-mail, o valor iria junto para o log. A conferência fica para a entrega final, com o código da aplicação inteiro; o caminho mais simples é a aplicação não gravar o texto da exceção do banco em produção.
- **Região:** tudo fica em us-east-1 (Estados Unidos). Para dados reais de clientes, a LGPD trata isso como transferência internacional (art. 33); a região de São Paulo (`sa-east-1`) evita a transferência, com preços mais altos. Para a disciplina, com dados de teste, o grupo usa us-east-1.
- **Pedidos do titular:** a exclusão existe (o admin exclui o cliente, e os dados pessoais somem); o próprio cliente vê e edita o cadastro dele. Exportar os dados de uma pessoa não existe, e um pedido assim seria atendido à mão pela barbearia.

## OWASP API Security Top 10 (2023)

Esqueleto do checklist. A coluna "Onde é tratado" diz o controle que o projeto tem; a coluna "Evidência" fica para a entrega final (`feat/entrega-final`), com o teste, o comando ou o print que prova cada um no código completo.

| Risco | Onde é tratado | Evidência (entrega final) | Situação |
|---|---|---|---|
| API1 Broken Object Level Authorization | Policies por recurso com `Gate::authorize` em cada método, escopos `visibleTo` na agenda e nos pagamentos, cliente só com o próprio cadastro | | A conferir |
| API2 Broken Authentication | Sanctum com validade e prefixo do token, limite de tentativas de login por e-mail e IP, troca e redefinição de senha que derrubam as outras sessões, confirmação de e-mail | | A conferir |
| API3 Broken Object Property Level Authorization | `role` fora do `$fillable` (gravado com `forceFill`), `user_id` escondido no JSON, contato do barbeiro só para a barbearia, cliente sem poder mudar o próprio e-mail | | A conferir |
| API4 Unrestricted Resource Consumption | Limites de requisição, `per_page` até 100, tamanho máximo dos campos e do corpo (8 MB), período do dashboard até 366 dias, Auto Scaling com teto e orçamento mensal | | A conferir |
| API5 Broken Function Level Authorization | Gates por tela, Policies por ação (como o estorno só pelo admin), rotas no grupo `verified`, testes de 401 e 403 por perfil | | A conferir |
| API6 Unrestricted Access to Sensitive Business Flows | Limite de agendamentos ativos do cliente e de dias à frente, janela de cancelamento, um pagamento por atendimento, espera de 10 minutos no reenvio do lembrete | | A conferir |
| API7 Server Side Request Forgery | A API não busca endereços que o usuário manda; os links dos e-mails saem do `APP_URL`, nunca do `Host` da requisição | | A conferir |
| API8 Security Misconfiguration | Headers e CSP, `APP_DEBUG=false` na produção, sem `X-Powered-By` e sem a versão do Nginx, hosts aceitos, CORS restrito, `trivy config` e security groups testados | | A conferir |
| API9 Improper Inventory Management | `docs/API.md` e a coleção do Postman como inventário, nomes de rota conferidos por teste, homolog e prod separados por ambiente | | A conferir |
| API10 Unsafe Consumption of APIs | A única API de fora é o SMTP do provedor de e-mail; actions, imagens e pacotes com versão fixa e varredura | | A conferir |

## Riscos aceitos

| Risco | Por que fica | O que protege hoje |
|---|---|---|
| **Usuário de teste com senha conhecida:** o `test@example.com`, admin, com a senha do `DatabaseSeeder` | Decisão 5 do plano (13/11/2026): nenhuma senha do projeto muda. Ele é o login de teste do ambiente local | O entrypoint de produção nunca roda seed; o admin de produção vem do `AdminUserSeeder`, com nome, e-mail e senha que só existem no ambiente do comando (`docs/nuvem/TERRAFORM.md`, "Criar o administrador"). Nunca rode o `DatabaseSeeder` fora do computador local |
| **Credenciais do MySQL de desenvolvimento no repositório:** `docker-compose.yml:16-19`, bloco `x-mysql-credentials` | Decisão 5 do plano: ficam como estão, no mesmo arquivo | Valem só para o MySQL do dev: a porta fica presa no 127.0.0.1, o banco só tem dados de teste, e nem a homolog nem a AWS usam essas credenciais |
| **Senha do RDS no repositório:** `terraform/main.tf:23` (e no state do Terraform) | Decisão 5 do plano: fica como está, no mesmo arquivo | O banco fica nas subnets privadas, e só o security group das instâncias alcança a 3306; o RDS exige TLS; o state fica num bucket privado, criptografado e só por HTTPS; a cópia no SSM vai por referência, num atributo write-only, sem segunda cópia no state; os testes conferem que a senha não aparece em outro arquivo nem no `user_data` |
| **Log de erro do Nginx só com `crit`:** numa falha grave (falta de memória, de arquivos abertos ou de portas, ou a pasta `public/` sumindo da imagem), a linha de erro ainda grava a requisição inteira, com a query string, o token dos links e o Referer, no grupo `web` do CloudWatch e no Loki local | O formato desse log não se configura no Nginx; o nível `crit` já tira as falhas comuns (pedido da `feat/infra-computacao`) | Falha grave é rara; os logs ficam 30 dias, e só quem tem acesso à conta lê; os erros que esse log deixou de gravar aparecem nos alarmes de 5xx do ALB e das instâncias e no painel de status do Grafana |
| **HTTP sem TLS enquanto o grupo não tiver domínio:** sem `certificate_arn`, o ALB atende só na 80, e senha e token passam sem criptografia pela internet | Certificado público pede um domínio, que precisa ser comprado (`docs/nuvem/TERRAFORM.md`, "HTTPS com domínio próprio") | Ambiente de disciplina, com dados de teste. Antes de clientes de verdade, domínio e certificado: o Terraform já aceita o `certificate_arn`, e a 80 passa a redirecionar para a 443 |
| **Cache sem senha (AUTH):** o Valkey aceita qualquer conexão que chegue | O Laravel conecta sem usuário, e a senha seria mais um segredo para girar | Só o security group das instâncias alcança a 6379, nas subnets privadas, com TLS |
| **Sem chave própria do KMS** no tópico dos alarmes, no ECR, nos log groups e no bucket do state, e sem login por IAM nem Performance Insights no RDS | Custo (US$ 1 por mês por chave) e limites do `db.t3.micro`; o motivo de cada um está no `terraform/.trivyignore.yaml` | A criptografia com as chaves da AWS continua ligada; quem lê cada coisa é decidido pelo IAM |
| **Sem WAF, sem access logs do ALB e sem VPC Flow Logs** | Cortes de excesso do plano (custo e ninguém para analisar) | Security groups em camadas, limites de requisição da aplicação e os alarmes |
| **Ferramentas locais com acesso amplo:** o Alloy e o cAdvisor leem o socket do Docker, e quem fala com ele controla o Docker do computador; o cAdvisor roda `privileged`, com a raiz da máquina virtual do Docker montada, que no Docker Desktop inclui o disco do Windows (o perfil do usuário, as chaves e o `homolog.env`); o Grafana abre sem login, só para ver; o k6 roda como root no container | São do monitoramento e da carga na máquina de quem desenvolve | Nada disso vai para a AWS; as portas ficam presas no 127.0.0.1, e o Grafana pede a senha do `homolog.env` para editar. O Alloy e o cAdvisor vão pela tag e pelo digest, e uma tag reescrita no registro não chega a eles; o Dependabot propõe as versões novas com 7 dias de espera |
| **Dados em us-east-1** | Preço e disponibilidade dos serviços; dados de teste | Veja "LGPD", "O que ainda falta olhar" |

Os itens de cada decisão e de cada aceite do Trivy estão no `terraform/.trivyignore.yaml`, um por recurso, com o motivo. Item novo nesta lista entra junto com o código que cria o risco.

## Antes de abrir para clientes de verdade

O `docs/nuvem/go-live-checklist.md` lista o que conferir. Do ponto de vista da segurança, o mínimo é: domínio e certificado no ALB, o `alarm_email` e o `budget_alert_email` preenchidos e confirmados, o teste de restore do `docs/nuvem/BACKUP.md` feito, o admin inicial criado pelo `AdminUserSeeder` e o checklist OWASP acima com as evidências.
