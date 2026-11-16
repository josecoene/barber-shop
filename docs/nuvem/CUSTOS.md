# Custos do BarberFlow na AWS

Este guia estima quanto o que o Terraform cria custa por mês em us-east-1, na homolog e na prod, mostra de onde vem cada preço e explica como apagar tudo depois da apresentação, para a conta não continuar cobrando.

**Situação:** estimativa, nada foi aplicado na AWS. Os preços são os da lista oficial da AWS de 14/11/2026 (seção "Fonte dos preços"). O uso (tráfego, logs, imagens) é suposto para um projeto pequeno e está escrito em cada linha. A estimativa não desconta o nível gratuito nem créditos da conta do grupo: contas novas da AWS ganham créditos, e contas mais antigas podem ter horas grátis de EC2, RDS e ALB no primeiro ano. Confira no Billing da conta antes de aplicar.

## Por mês, ligado o mês inteiro

Um mês tem 730 horas. Os valores estão em dólares (US$).

### Homolog (`envs/homolog.tfvars.example`)

| Item | O que o Terraform cria | Conta | US$ por mês |
|---|---|---|---|
| Instâncias | 1 `t3.micro` (Linux), o desejado da homolog | 0,0104 × 730 | 7,59 |
| Disco das instâncias | 30 GB gp3 | 30 × 0,08 | 2,40 |
| IP público das instâncias | 1 endereço IPv4 | 0,005 × 730 | 3,65 |
| ALB | 1 balanceador | 0,0225 × 730 | 16,43 |
| Capacidade do ALB (LCU) | Pouco tráfego: 0,1 LCU em média, suposto | 0,1 × 730 × 0,008 | 0,58 |
| IPs públicos do ALB | 2 endereços IPv4, um por zona | 2 × 0,005 × 730 | 7,30 |
| Banco (RDS) | `db.t3.micro` MySQL, uma zona | 0,017 × 730 | 12,41 |
| Disco do banco | 20 GB gp3, uma zona | 20 × 0,115 | 2,30 |
| Backup do banco | 7 dias, até o tamanho do disco | Grátis até 20 GB | 0,00 |
| Cache (ElastiCache) | 1 nó `cache.t4g.micro` Valkey | 0,0128 × 730 | 9,34 |
| Alarmes | 12 alarmes (os 10 primeiros da conta são grátis) | 2 × 0,10 | 0,20 |
| Dashboard | 1 (os 3 primeiros da conta são grátis) | | 0,00 |
| Logs | Containers e banco, menos de 5 GB por mês, suposto (os 5 primeiros GB são grátis) | | 0,00 |
| Imagens (ECR) | Perto de 1 GB nos 2 repositórios, suposto | 1 × 0,10 | 0,10 |
| SNS, SSM, chaves da AWS, orçamento e state | Avisos por e-mail (os 1.000 primeiros por mês são grátis), parâmetros padrão, chaves gerenciadas pela AWS, orçamento sem ação e um state de poucos KB | | 0,00 |
| Transferência para a internet | Menos de 100 GB por mês (os 100 primeiros são grátis, somando todos os serviços) | | 0,00 |
| **Total** | | | **perto de 62,30** |

### Prod (`envs/prod.tfvars.example`)

| Item | O que muda em relação à homolog | Conta | US$ por mês |
|---|---|---|---|
| Instâncias | 2 `t3.micro` (o desejado da prod) | 2 × 7,59 | 15,18 |
| Disco das instâncias | 2 × 30 GB | 2 × 2,40 | 4,80 |
| IP público das instâncias | 2 endereços | 2 × 3,65 | 7,30 |
| ALB, LCU e IPs do ALB | Igual | | 24,31 |
| Banco (RDS) | Multi-AZ (uma réplica de espera em outra zona) | 0,034 × 730 | 24,82 |
| Disco do banco | 20 GB gp3, Multi-AZ | 20 × 0,23 | 4,60 |
| Cache | 2 nós (primário e réplica) | 2 × 9,34 | 18,69 |
| Alarmes | 15 alarmes (o cache tem 2 nós) | 5 × 0,10 | 0,50 |
| Imagens, dashboard, logs e o resto | Igual | | 0,10 |
| **Total** | | | **perto de 100,30** |

- **Com a escala no máximo** (4 instâncias), a prod soma mais 2 instâncias com disco e IP: perto de US$ 27,30 a mais por mês, enquanto a escala durar.
- **Homolog e prod na mesma conta:** perto de US$ 163,60 por mês. O US$ 1 a mais vem dos alarmes, porque os 10 grátis valem uma vez por conta (27 alarmes no total).
- **O que mais pesa:** o ALB com os IPs públicos (perto de US$ 24 por ambiente) e o banco. O IPv4 público cobra por hora desde 2024, por isso as instâncias e os nós do ALB entram na conta.
- **O que pode cobrar a mais:** as instâncias `t3` e o banco `db.t3.micro` rodam no modo `unlimited`: CPU acima da linha de base por muito tempo cobra créditos extras. Tráfego acima de 100 GB por mês cobra US$ 0,09 por GB, logs acima de 5 GB cobram US$ 0,50 por GB, e backup ou snapshot acima do tamanho do banco cobram US$ 0,095 por GB por mês.

### O orçamento do `budget.tf`

O orçamento mensal (`budget_limit_usd`, padrão US$ 30) avisa por e-mail com 80% do gasto real (US$ 24) e quando a previsão do mês passa de 100%. Com a homolog ligada o mês inteiro (perto de US$ 62), o aviso de previsão chega já nos primeiros dias e o de gasto real, perto do 12º dia. Para alguns dias de apresentação, os US$ 30 bastam (próxima seção). Se o grupo deixar os ambientes no ar o mês inteiro, suba o `budget_limit_usd` no `.tfvars` para perto do total da tabela, ou o aviso perde o sentido.

## Para a apresentação

O plano é aplicar a homolog só para a apresentação e destruir logo depois. Quase tudo cobra por hora: a homolog custa perto de US$ 0,085 por hora (US$ 62,30 ÷ 730).

| Tempo no ar | Homolog | Homolog e prod |
|---|---|---|
| 1 dia | perto de US$ 2,05 | perto de US$ 5,40 |
| 3 dias | perto de US$ 6,15 | perto de US$ 16,15 |
| 1 semana | perto de US$ 14,35 | perto de US$ 37,65 |

Depois do `destroy`, sobram só o snapshot final do banco (cobra pelo tamanho dos dados, alguns centavos por mês com os dados de teste, até alguém apagar) e o bucket do state (centavos). As imagens do ECR precisam ser apagadas antes do `destroy` (seção abaixo).

## Destruir depois da apresentação

O passo a passo do `destroy` está no `docs/nuvem/TERRAFORM.md`, "Aplicar na AWS". Em resumo, com a função `tfaws` e o perfil do grupo:

1. Apague as imagens dos dois repositórios do ECR no console (o Terraform não apaga repositório com imagem).
2. Na prod, ponha `db_deletion_protection = false` e `alb_deletion_protection = false` no `envs/prod.tfvars` e rode `plan` e `apply` antes do `destroy`.
3. Rode o `destroy` de cada ambiente, com o `init` feito na `key` dele:

   ```powershell
   tfaws init -reconfigure "-backend-config=backend.hcl" "-backend-config=key=homolog/terraform.tfstate"
   tfaws destroy "-var-file=envs/homolog.tfvars"
   ```

4. Se não precisar mais do banco, apague o snapshot final (`barberflow-<ambiente>-db-final`) no console do RDS: ele cobra até ser apagado.
5. Por último, se o grupo não for aplicar de novo, esvazie e apague o bucket do state pelo bootstrap (`docs/nuvem/TERRAFORM.md`, "Aplicar na AWS").
6. No dia seguinte, confira que nada ficou ligado. Tudo o que o Terraform cria leva a tag `Project=barberflow` (o `default_tags` do `providers.tf`):

   ```powershell
   aws resourcegroupstaggingapi get-resources --region us-east-1 --tag-filters Key=Project,Values=barberflow --query "ResourceTagMappingList[].ResourceARN" --output table
   ```

   Sem saída, não sobrou nada com a tag. Confira também o Cost Explorer do Billing, que mostra o gasto de cada serviço por dia.

## Fonte dos preços

Lista oficial de preços da AWS (Price List), região us-east-1, consultada em 14/11/2026, nos arquivos `https://pricing.us-east-1.amazonaws.com/offers/v1.0/aws/<serviço>/current/us-east-1/index.json`, sempre o preço sob demanda:

| Serviço | Preços usados | Publicação do arquivo |
|---|---|---|
| EC2 (`t3.micro`, Linux) | US$ 0,0104 por hora | 14/11/2026 (dados da página de preços sob demanda do EC2) |
| EBS (gp3) | US$ 0,08 por GB por mês | 14/11/2026 (dados da página de preços do EBS) |
| VPC (IPv4 público) | US$ 0,005 por endereço por hora | 05/11/2026 |
| Elastic Load Balancing (ALB) | US$ 0,0225 por hora e US$ 0,008 por LCU-hora | 11/11/2026 |
| RDS (`db.t3.micro` MySQL) | US$ 0,017 por hora numa zona e US$ 0,034 Multi-AZ; disco gp3 a US$ 0,115 por GB por mês (US$ 0,23 Multi-AZ); backup acima do tamanho do banco a US$ 0,095 por GB por mês | 13/11/2026 |
| ElastiCache (`cache.t4g.micro` Valkey) | US$ 0,0128 por hora por nó (o Redis custa US$ 0,016) | 13/11/2026 |
| CloudWatch | US$ 0,10 por alarme por mês; logs a US$ 0,50 por GB recebido e US$ 0,03 por GB guardado por mês | 04/11/2026 |
| ECR | US$ 0,10 por GB por mês | 13/11/2026 |
| SNS | 1.000 e-mails grátis por mês, depois US$ 2 a cada 100 mil | 12/11/2026 |
| Transferência de dados | US$ 0,09 por GB para a internet depois do nível grátis | 11/11/2026 |

O que é grátis e os limites do nível grátis: página de preços do CloudWatch (3 dashboards, 10 alarmes e 5 GB de logs por mês, e US$ 3 por dashboard depois dos 3) e perguntas frequentes da rede global da AWS (100 GB por mês de transferência para a internet, somando todos os serviços e regiões), as duas consultadas em 14/11/2026. O orçamento sem ação do AWS Budgets não custa nada (`docs/nuvem/TERRAFORM.md`, "Orçamento").

Para conferir de novo, a calculadora da AWS (https://calculator.aws) aceita os mesmos itens das tabelas; os preços mudam, e a estimativa vale para a data acima.
