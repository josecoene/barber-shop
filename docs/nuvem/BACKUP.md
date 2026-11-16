# Backup e restore do banco

Este guia explica como o banco MySQL da AWS (RDS) do BarberFlow é protegido contra perda de dados, quanto dado e quanto tempo um problema pode custar (RPO e RTO) e como voltar um backup, passo a passo.

**Situação: validado só no código.** O Terraform ainda não foi aplicado na AWS, então nenhum comando deste guia foi rodado. As configurações estão no `terraform/main.tf` e passam pelo `terraform validate` e pelo `terraform test` com o provider simulado (`docs/nuvem/TERRAFORM.md`); os comandos foram conferidos na documentação da AWS CLI. O teste de restore do fim do guia é o que prova tudo de verdade, antes do go-live.

## O que protege o banco

| Proteção | Como está | Onde fica |
|---|---|---|
| Backup automático | Um por dia, das 03:00 às 04:00 (Brasília), guardado por 7 dias. Junto, o RDS guarda o log de transações, que permite voltar a qualquer minuto desses 7 dias | `backup_retention_period` e `backup_window` no `terraform/main.tf` |
| Snapshot final | Quando o banco é apagado, o RDS tira o snapshot `barberflow-<ambiente>-db-final`, que fica até alguém apagar | `final_snapshot_identifier` |
| Proteção contra exclusão | Na prod, nem o `terraform destroy` nem o console apagam o banco | variável `db_deletion_protection` |
| Multi-AZ | Na prod, uma réplica de espera em outra zona recebe cada gravação na hora e assume sozinha se a zona do banco cair, em 1 a 2 minutos, sem perder dado | variável `db_multi_az` |
| Criptografia | Disco, backups e snapshots criptografados com a chave padrão do RDS | `storage_encrypted` |
| Tags | Os snapshots levam as tags do banco (projeto e ambiente) | `copy_tags_to_snapshot` |

O backup automático e o snapshot final são do próprio RDS. O plano cortou o AWS Backup como excesso: para este projeto, ele repetiria o que o RDS já faz.

## RPO e RTO

- **RPO** (quanto dado pode se perder): **cerca de 5 minutos**. O RDS manda o log de transações para o S3 a cada 5 minutos, e o restore volta até o último momento restaurável (`LatestRestorableTime`), que costuma ficar a menos de 5 minutos do agora. Na prod, a queda de uma zona não perde nada, porque a réplica do Multi-AZ recebe cada gravação antes de o banco responder; os 5 minutos valem para o restore.
- **RTO** (quanto tempo até a aplicação voltar): **estimado em 45 a 60 minutos**, ainda não medido:

| Etapa | Estimativa |
|---|---|
| Restore do banco (20 GB num `db.t3.micro`) | 15 a 30 minutos |
| Trocar o `DB_HOST` no SSM | 1 minuto |
| Instance refresh do Auto Scaling (`feat/infra-computacao`) | 10 a 20 minutos |
| Conferir a aplicação e os dados | 5 minutos |

Troque a estimativa pelo tempo medido no teste de restore.

## O que não está neste backup

- **Cache e sessões (Valkey):** não têm backup. Depois de um problema no cache, quem estava logado entra de novo, e o cache se refaz sozinho.
- **Parâmetros do SSM:** o Terraform recria todos, menos o valor do `APP_KEY`, que só o operador tem. Guarde o `APP_KEY` de cada ambiente num cofre de senhas do grupo: se ele se perder, as sessões abertas caem e o que a aplicação criptografou com ele não abre mais.
- **Imagens do ECR:** o deploy (`feat/ci-cd`) refaz a partir do Git.
- **Logs:** ficam 30 dias no CloudWatch (variável `log_retention_days`).

## Antes de começar

- AWS CLI v2 com o perfil do grupo, que precisa de permissão no RDS, no SSM e no Auto Scaling.
- Os comandos são do PowerShell e usam a prod como exemplo. Na homolog, troque `prod` por `homolog` e use `--no-multi-az` e `--no-deletion-protection`.
- Os nomes seguem o padrão `barberflow-<ambiente>-...` e também aparecem no `terraform output` (`db_identifier`, `db_subnet_group_name`, `db_parameter_group_name` e `db_security_group_id`). O ID do security group do banco vem da própria AWS:

```powershell
$env:AWS_PROFILE = "nome-do-perfil-do-grupo"
$env:AWS_REGION = "us-east-1"
$origem = "barberflow-prod-db"
$subnets = "barberflow-prod-db"
$params = "barberflow-prod-mysql84"
$sg = aws ec2 describe-security-groups --filters Name=group-name,Values=barberflow-prod-db --query "SecurityGroups[0].GroupId" --output text
```

O banco restaurado precisa receber o grupo de subnets, o security group e o parameter group do projeto em todo restore. Sem eles, a AWS usa os padrões da conta: o banco vai para a VPC padrão, fora do alcance da aplicação, e sem o parameter group deixa de exigir TLS.

A senha do banco restaurado é a mesma do original (o restore copia o usuário e a senha), então o `DB_PASSWORD` do SSM não muda.

## Restore de um minuto do passado

Use quando alguém apagou ou estragou dados (um `DELETE` errado, uma migration ruim) e o banco continua no ar. O banco atual fica intacto: o restore cria um banco novo, e você compara os dois antes de trocar.

1. Escolha o horário, em UTC (Brasília mais 3 horas), um pouco antes do erro. Para ver até onde dá para voltar:

   ```powershell
   aws rds describe-db-instances --db-instance-identifier $origem --query "DBInstances[0].LatestRestorableTime" --output text
   ```

2. Crie o banco novo naquele minuto:

   ```powershell
   $novo = "barberflow-prod-db-restore"
   aws rds restore-db-instance-to-point-in-time `
     --source-db-instance-identifier $origem `
     --target-db-instance-identifier $novo `
     --restore-time 2026-11-13T15:00:00Z `
     --db-subnet-group-name $subnets `
     --vpc-security-group-ids $sg `
     --db-parameter-group-name $params `
     --no-publicly-accessible `
     --multi-az `
     --storage-type gp3 `
     --backup-retention-period 7 `
     --copy-tags-to-snapshot `
     --deletion-protection `
     --enable-cloudwatch-logs-exports error slowquery
   ```

   Para voltar ao último momento possível, troque o `--restore-time ...` por `--use-latest-restorable-time`.

3. Espere o banco ficar pronto (o comando volta sozinho quando ele fica disponível):

   ```powershell
   aws rds wait db-instance-available --db-instance-identifier $novo
   ```

4. Troque o `DB_HOST` do SSM pelo endereço do banco novo:

   ```powershell
   $endereco = aws rds describe-db-instances --db-instance-identifier $novo --query "DBInstances[0].Endpoint.Address" --output text
   aws ssm put-parameter --name /barberflow/prod/DB_HOST --type String --value $endereco --overwrite
   ```

5. Troque as instâncias com o instance refresh do Auto Scaling (`feat/infra-computacao`). Cada instância nova lê o SSM ao subir e já conecta no banco novo:

   ```powershell
   aws autoscaling start-instance-refresh --auto-scaling-group-name <nome do Auto Scaling, saída da feat/infra-computacao>
   ```

6. Confira a aplicação (o `/api/health` responde 200 com banco e cache no ar) e os dados que motivaram o restore.

7. Devolva o banco ao Terraform (seção abaixo). Até lá, não rode `terraform apply`: o `DB_HOST` é mantido pelo Terraform, e o apply voltaria o endereço para o banco antigo.

## Restore de um snapshot

Use quando o banco foi apagado (fica o snapshot final) ou para voltar a um snapshot tirado à mão.

1. Liste os snapshots do banco:

   ```powershell
   aws rds describe-db-snapshots --db-instance-identifier $origem --query "DBSnapshots[].[DBSnapshotIdentifier,SnapshotCreateTime,Status]" --output table
   ```

2. Crie o banco a partir do snapshot escolhido (aqui, o final):

   ```powershell
   $novo = "barberflow-prod-db-restore"
   aws rds restore-db-instance-from-db-snapshot `
     --db-instance-identifier $novo `
     --db-snapshot-identifier barberflow-prod-db-final `
     --db-subnet-group-name $subnets `
     --vpc-security-group-ids $sg `
     --db-parameter-group-name $params `
     --no-publicly-accessible `
     --multi-az `
     --storage-type gp3 `
     --backup-retention-period 7 `
     --copy-tags-to-snapshot `
     --deletion-protection `
     --enable-cloudwatch-logs-exports error slowquery
   ```

3. Siga os passos 3 a 7 do restore anterior: esperar, trocar o `DB_HOST`, instance refresh, conferir e devolver ao Terraform.

Se o ambiente inteiro foi destruído, o `terraform apply` recria a rede, o cache e um banco vazio com o nome original. Depois restaure o snapshot final com outro nome, como acima, e siga a seção seguinte.

## Devolver o banco ao Terraform

Depois do restore, o Terraform ainda cuida do banco antigo, e o `DB_HOST` do SSM aponta para um banco que ele não conhece. Este passo põe o banco restaurado no lugar do antigo, com o nome original.

As duas trocas de nome são feitas pela AWS CLI, com `--apply-immediately`, e nunca pelo Terraform. O `aws_db_instance` do `main.tf` não tem `apply_immediately`, então uma troca de nome feita pelo `apply` ficaria pendente até a janela de manutenção de domingo. Até lá o endereço não mudaria, e no domingo o endereço antigo sumiria de repente, derrubando a aplicação.

A aplicação fica fora do ar do passo 2 até o fim do passo 5: o endereço `-restore` some assim que a troca de nome começa, o banco reinicia e o nome original leva cerca de 10 minutos para valer no DNS. Faça com a barbearia fechada e rode os passos 2 a 5 em seguida.

Os comandos usam as variáveis de "Antes de começar" e o `$novo` do restore (`barberflow-prod-db-restore`). Numa janela nova do terminal, defina todas de novo.

1. Tire o banco antigo do caminho, renomeando. Se ele foi apagado, pule este passo.

   ```powershell
   aws rds modify-db-instance --db-instance-identifier $origem --new-db-instance-identifier "$origem-antigo" --apply-immediately
   do { Start-Sleep -Seconds 30 } until ((aws rds describe-db-instances --db-instance-identifier "$origem-antigo" --query "DBInstances[0].DBInstanceStatus" --output text 2>$null) -eq "available")
   ```

   A segunda linha espera o banco aparecer com o nome novo e ficar disponível, conferindo a cada 30 segundos. O `aws rds wait db-instance-available` não serve aqui: ele para com erro (`DBInstanceNotFound`) se o nome novo ainda não existe.

2. Dê ao banco restaurado o nome original e espere do mesmo jeito:

   ```powershell
   aws rds modify-db-instance --db-instance-identifier $novo --new-db-instance-identifier $origem --apply-immediately
   do { Start-Sleep -Seconds 30 } until ((aws rds describe-db-instances --db-instance-identifier $origem --query "DBInstances[0].DBInstanceStatus" --output text 2>$null) -eq "available")
   ```

3. Na pasta `terraform/`, com o `init` feito na `key` do ambiente, troque o banco antigo pelo restaurado no state:

   ```powershell
   tfaws state rm aws_db_instance.barbershop_db
   tfaws import "-var-file=envs/prod.tfvars" aws_db_instance.barbershop_db barberflow-prod-db
   ```

4. Rode o `plan` e leia com calma:

   ```powershell
   tfaws plan "-var-file=envs/prod.tfvars" "-out=prod.tfplan"
   ```

   O plano deve ter só mudanças no lugar (`~ update in-place`). No banco: a senha (o import não traz a senha da AWS; o valor é o mesmo), atributos que só o Terraform guarda, como o `final_snapshot_identifier`, e as janelas de backup e de manutenção, se o restore não tiver copiado. No SSM: o `DB_HOST` voltando ao endereço do nome original. Pare se aparecer `must be replaced` no `aws_db_instance.barbershop_db` (o plano apagaria o banco restaurado) ou troca de `identifier` (a troca de nome do passo 2 não terminou).

5. Aplique o plano e faça um novo instance refresh, para as instâncias lerem o `DB_HOST` de volta:

   ```powershell
   tfaws apply prod.tfplan
   aws autoscaling start-instance-refresh --auto-scaling-group-name <nome do Auto Scaling>
   ```

6. Depois de conferir a aplicação de novo, apague o banco antigo. Na prod, desligue a proteção contra exclusão antes:

   ```powershell
   aws rds modify-db-instance --db-instance-identifier "$origem-antigo" --no-deletion-protection --apply-immediately
   aws rds delete-db-instance --db-instance-identifier "$origem-antigo" --final-db-snapshot-identifier "$origem-antigo-final"
   ```

7. O RDS criou sozinho, e sem prazo de validade, os log groups dos nomes temporários: `/aws/rds/instance/barberflow-prod-db-restore/error` e `/slowquery`, e os mesmos com `barberflow-prod-db-antigo`. Os do nome original são os do Terraform e ficam. Apague os temporários no console do CloudWatch ou com `aws logs delete-log-group --log-group-name <nome>`.

Se não precisar comparar os dois bancos, existe um caminho mais curto: renomeie o banco antigo primeiro (passo 1 desta seção) e restaure já com o nome original, com `$novo = $origem` antes do comando de restore. No restore de um minuto do passado, a origem passa a ser o banco renomeado: use `--source-db-instance-identifier "$origem-antigo"`, porque o banco antigo não se chama mais `$origem`. No restore de um snapshot nada muda: o nome do snapshot continua o mesmo. O endereço do banco não muda, o `DB_HOST` também não, e só faltam os passos 3 a 7, sem o instance refresh do passo 5. A aplicação fica fora do ar desde a troca de nome até o banco novo ficar pronto.

## Teste de restore antes do go-live

Rode uma vez em cada ambiente, antes de abrir para os clientes, e anote o resultado neste guia:

1. Restaure o minuto de 10 minutos atrás num banco de teste, sem Multi-AZ e sem proteção contra exclusão (sai mais barato e apaga direto):

   ```powershell
   $teste = "barberflow-prod-db-teste-restore"
   $inicio = Get-Date
   aws rds restore-db-instance-to-point-in-time --source-db-instance-identifier $origem --target-db-instance-identifier $teste --restore-time ((Get-Date).ToUniversalTime().AddMinutes(-10).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'")) --db-subnet-group-name $subnets --vpc-security-group-ids $sg --db-parameter-group-name $params --no-publicly-accessible --no-multi-az --no-deletion-protection
   aws rds wait db-instance-available --db-instance-identifier $teste
   (Get-Date) - $inicio
   ```

   O último comando mostra quanto o restore levou: é a primeira linha do RTO.

2. Entre numa instância do app pelo Session Manager (`aws ssm start-session --target <ID da instância>`, com o plugin do Session Manager instalado no computador) e conte os registros nos dois bancos, com um cliente MySQL descartável. A senha é pedida na tela e não fica no histórico:

   ```bash
   docker run --rm -it mysql:8.4.13 mysql -h <endereço do banco> -u admincloud -p --ssl-mode=REQUIRED barbershop -e "SELECT COUNT(*) FROM clients; SELECT COUNT(*) FROM barbers; SELECT COUNT(*) FROM services;"
   ```

   Rode uma vez com o endereço do banco de produção e outra com o do banco de teste. Os números devem bater, a não ser que alguém tenha gravado algo nos últimos 10 minutos.

3. Apague o banco de teste (ele não exporta log, então não criou log group):

   ```powershell
   aws rds delete-db-instance --db-instance-identifier $teste --skip-final-snapshot --delete-automated-backups
   ```

4. Anote aqui a data, o ambiente, o tempo do restore e se os números bateram.

| Data | Ambiente | Tempo do restore | Dados conferidos |
|---|---|---|---|
| (ainda não rodado) | | | |

## Custos

- O backup automático não cobra até o tamanho do banco; acima disso, e para todo snapshot que ficar guardado (o final, inclusive), a AWS cobra o armazenamento por GB por mês.
- Um banco restaurado cobra por hora como o original até ser apagado. Não esqueça o banco antigo e o de teste ligados.
- A estimativa do projeto fica no `docs/nuvem/CUSTOS.md` (branch `feat/observabilidade`).
