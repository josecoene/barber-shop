#!/bin/sh
# Entrypoint do alvo dev (docker-compose.yml). Prepara o projeto montado do host antes do php-fpm.
# Cada passo só roda quando falta ou mudou alguma coisa, então subir de novo é seguro.
# Nunca roda migrate:fresh nem seed: os dados do banco de dev ficam como estão.
set -eu

cd /var/www/html

if [ ! -f artisan ]; then
    echo "[entrypoint-dev] ERRO: /var/www/html não tem o projeto. Rode o docker compose na raiz do repositório." >&2
    exit 1
fi

# O app e o scheduler usam o mesmo vendor e, num "docker compose restart", sobem juntos.
# A trava faz um esperar o outro terminar os passos abaixo (dois composer install ao mesmo
# tempo no mesmo vendor estragariam a pasta).
mkdir -p vendor
exec 9>vendor/.entrypoint-dev.lock
flock 9

if [ ! -f .env ]; then
    echo "[entrypoint-dev] Criando o .env a partir do .env.example"
    cp .env.example .env
fi

# O vendor fica num volume do Docker. Instala quando ele está vazio ou quando o composer.lock
# mudou desde a última instalação (um git pull que trouxe pacote novo, por exemplo). O hash do
# lock instalado fica guardado no próprio volume, em vendor/.composer-lock.sha256.
lock_hash="$(sha256sum composer.lock | cut -d ' ' -f 1)"
if [ ! -f vendor/autoload.php ] || [ "$(cat vendor/.composer-lock.sha256 2>/dev/null || true)" != "$lock_hash" ]; then
    echo "[entrypoint-dev] Instalando as dependências do Composer (na primeira vez leva alguns minutos)"
    composer install --no-interaction --prefer-dist --no-progress
    echo "$lock_hash" > vendor/.composer-lock.sha256
fi

# Chave vazia no ambiente e no .env (APP_KEY= ou APP_KEY="")
if [ -z "${APP_KEY:-}" ] && grep -Eq '^APP_KEY=("")?[[:space:]]*$' .env; then
    echo "[entrypoint-dev] Gerando a APP_KEY no .env"
    php artisan key:generate --force
fi

echo "[entrypoint-dev] Rodando as migrations pendentes"
php artisan migrate --force

# Solta a trava antes de virar o php-fpm (ou o schedule:work), que senão a seguraria para sempre
exec 9>&-

exec "$@"
