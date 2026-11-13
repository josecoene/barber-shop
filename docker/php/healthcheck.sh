#!/bin/sh
# Healthcheck do PHP-FPM (instalado na imagem como php-fpm-healthcheck).
# Pergunta o ping.path (/ping) direto ao PHP-FPM pelo protocolo FastCGI, sem passar pelo Nginx.
# Sai com 0 só se o pool responder "pong" (configurado em docker/php/php-fpm.d/zz-barberflow.conf).
set -eu

SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
