# Cache e sessões do Laravel no ElastiCache, com o Valkey (o fork de código aberto do Redis,
# que a extensão redis do PHP usa sem mudança). As travas do migrate --isolated e do
# onOneServer() ficam aqui, num cache que todas as instâncias enxergam.
#
# - Replication group sem cluster mode: um primário e, na prod, uma réplica em outra zona,
#   com failover automático. A aplicação usa um endereço só, o do primário.
# - Subnets privadas (sem rota para a internet) e só o security group do app entra (sg.tf).
# - Criptografado em repouso e em trânsito (TLS). A aplicação conecta com
#   REDIS_HOST=tls://<endereço do primário> e REDIS_PORT=6379 (parâmetros em ssm.tf).
#   A homolog local roda um Redis sem TLS, então o TLS daqui fica validado só no código.
# - Sem senha (AUTH): só o security group do app alcança a porta 6379.
# - Valkey 9.1, a versão mais nova no ElastiCache (lançada lá em junho de 2026). Com a
#   atualização automática ligada, a AWS aplica as correções da 9.1 na janela de manutenção.

resource "aws_elasticache_subnet_group" "cache" {
  name        = "${local.name_prefix}-cache"
  description = "Subnets privadas do cache do BarberFlow"
  subnet_ids  = aws_subnet.private[*].id
}

resource "aws_elasticache_replication_group" "cache" {
  replication_group_id = "${local.name_prefix}-cache"
  description          = "Cache e sessoes do BarberFlow (${var.environment})"

  engine         = "valkey"
  engine_version = "9.1"
  node_type      = var.cache_node_type
  port           = 6379

  # Um shard só (sem cluster mode). Mais de um nó vira primário + réplicas.
  cluster_mode       = "disabled"
  num_cache_clusters = var.cache_num_cache_clusters

  # O failover automático e o Multi-AZ andam juntos: se a zona do primário cair, a réplica
  # da outra zona assume. A variável recusa failover com um nó só.
  automatic_failover_enabled = var.cache_automatic_failover
  multi_az_enabled           = var.cache_automatic_failover

  subnet_group_name  = aws_elasticache_subnet_group.cache.name
  security_group_ids = [aws_security_group.cache.id]

  at_rest_encryption_enabled = true
  transit_encryption_enabled = true

  auto_minor_version_upgrade = true

  # UTC: domingo de 05:30 a 06:30 em Brasília, depois da manutenção do RDS.
  maintenance_window = "sun:08:30-sun:09:30"

  tags = {
    Name = "${local.name_prefix}-cache"
  }
}
