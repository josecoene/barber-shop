#!/bin/sh
# Entrypoint de produção (alvo app). Roda antes do php-fpm e do agendador (schedule:work).
# A imagem já sai pronta: este script nunca gera chave, nunca roda seed e nunca muda dono de arquivo.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] ERRO: APP_KEY está vazio. Defina a chave no ambiente do container" >&2
    echo "[entrypoint] (gere uma com: php artisan key:generate --show) e suba de novo." >&2
    exit 1
fi

# No docker-compose.prod.yml o storage/framework é uma pasta em memória e nasce vazia.
# Recria as subpastas que o Laravel espera; sem a de views, o optimize para com "View path not found".
# Fora do compose (pastas que já vêm da imagem), o mkdir -p não muda nada.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views

# Cache de config, rotas, eventos e views. A config fica congelada com as variáveis deste container.
php artisan optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    # --isolated: se várias instâncias subirem juntas, só uma roda as migrations
    # (a trava fica no cache compartilhado, o Redis). As outras seguem sem migrar.
    php artisan migrate --force --isolated
fi

exec "$@"
