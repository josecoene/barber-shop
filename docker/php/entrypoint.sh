#!/bin/sh
# Entrypoint de produção (alvo app). Roda antes do php-fpm e do agendador (schedule:work).
# A imagem já sai pronta: este script nunca gera chave, nunca roda seed e nunca muda dono de arquivo.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] ERRO: APP_KEY está vazio. Defina a chave no ambiente do container" >&2
    echo "[entrypoint] (gere uma com: php artisan key:generate --show) e suba de novo." >&2
    exit 1
fi

# A chave precisa ter 32 bytes, porque o config/app.php usa AES-256-CBC. O teste repete o do
# Laravel: tira o "base64:" do começo e decodifica. Uma chave errada, como o valor provisório
# do parâmetro APP_KEY no SSM, passaria pelo optimize, e o /up responderia 200 enquanto toda
# tela dá 500. Parando aqui, o container cai e o problema aparece na subida.
if ! php -r '$key = (string) getenv("APP_KEY"); if (str_starts_with($key, "base64:")) { $key = base64_decode(substr($key, 7)); } exit(strlen($key) === 32 ? 0 : 1);'; then
    echo "[entrypoint] ERRO: APP_KEY inválido. O Laravel precisa de uma chave de 32 bytes, como a do" >&2
    echo "[entrypoint] php artisan key:generate --show (base64:...). Na AWS, preencha o parâmetro APP_KEY" >&2
    echo "[entrypoint] do SSM (docs/nuvem/TERRAFORM.md, \"Preencher o APP_KEY\") e suba de novo." >&2
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
