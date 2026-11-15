# Imagem do BarberFlow (Laravel 13 em PHP 8.4), em vários estágios.
#
# Alvos que os compose usam (--target):
#   dev  docker-compose.yml: PHP-FPM com o Composer; o código vem montado do host.
#   app  produção e homolog: PHP-FPM com o código, o vendor e o public/build dentro da imagem.
#   web  produção e homolog: Nginx com o public/ e os assets do Vite.
#
# Build manual (na raiz do repositório):
#   docker build -f docker/php/Dockerfile --target app -t barberflow-app:local .
#   docker build -f docker/php/Dockerfile --target web -t barberflow-web:local .
#
# Todas as versões são fixas de propósito. Para atualizar, troque a tag aqui e rode o build de novo.

# ---------------------------------------------------------------------------
# composer: só para copiar o binário do Composer numa versão fixa
# ---------------------------------------------------------------------------
FROM composer:2.11.2 AS composer

# ---------------------------------------------------------------------------
# base: PHP-FPM com as extensões e a configuração comuns ao dev e à produção
# ---------------------------------------------------------------------------
FROM php:8.4.27-fpm-bookworm AS base

# Extensão redis do PECL, com o pacote conferido por SHA-256
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

# Bundle de CA do RDS, usado na conexão TLS com o MySQL da AWS (MYSQL_ATTR_SSL_CA)
ARG RDS_CA_BUNDLE_URL=https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem
ARG RDS_CA_BUNDLE_SHA256=90a6f1a7978a1b1b096dca6c57511ca48d3b8cf052f8e704baf358f34ea2f21b

# pdo_mysql: banco (MySQL local e RDS). redis: cache e sessão (Redis local e ElastiCache).
# libfcgi-bin traz o cgi-fcgi, que o healthcheck usa para falar com o PHP-FPM.
# A imagem oficial (Debian) deixa instaladas as ferramentas de compilação das extensões
# (PHPIZE_DEPS: gcc, g++, make, autoconf e outras). Elas saem no fim deste RUN, depois de
# compilar o pdo_mysql e o redis: a imagem final não leva compilador nem os pacotes que vêm com
# ele (cabeçalhos do kernel, perl e outros, que só somavam alertas no Trivy). Para compilar outra
# extensão, instale as ferramentas no mesmo RUN dela (apt-get install $PHPIZE_DEPS) e tire no fim.
# O libc6-dev vai pelo nome: na lista ele aparece como libc-dev, um pacote virtual, e o apt não
# remove o pacote de verdade por esse nome.
# O OPcache já vem habilitado na imagem oficial; as últimas linhas só conferem que as três extensões carregaram.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfcgi-bin; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    curl -fsSL --retry 3 -o /tmp/redis.tgz "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz"; \
    echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -; \
    pecl install /tmp/redis.tgz; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/redis.tgz /tmp/pear ~/.pearrc; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS libc6-dev; \
    command -v cgi-fcgi; \
    if command -v gcc || command -v make || [ -e /usr/include/stdio.h ] || [ -e /usr/include/linux/version.h ]; then \
        echo 'as ferramentas de compilação ficaram na imagem' >&2; exit 1; \
    fi; \
    php -m | grep -qx 'Zend OPcache'; \
    php -m | grep -qx 'pdo_mysql'; \
    php -m | grep -qx 'redis'

# Quando a AWS publicar um bundle novo, o build para aqui: baixe o arquivo, confira e troque o hash acima.
RUN set -eux; \
    curl -fsSL --retry 3 -o /etc/ssl/certs/rds-global-bundle.pem "${RDS_CA_BUNDLE_URL}"; \
    echo "${RDS_CA_BUNDLE_SHA256}  /etc/ssl/certs/rds-global-bundle.pem" | sha256sum -c -; \
    chmod 0644 /etc/ssl/certs/rds-global-bundle.pem

# php.ini de produção da própria imagem, com os ajustes do BarberFlow por cima (lidos em ordem alfabética)
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/php/conf.d/barberflow.ini /usr/local/etc/php/conf.d/zz-barberflow.ini
COPY docker/php/conf.d/otimizado.ini /usr/local/etc/php/conf.d/zz-otimizado.ini

# Pool do PHP-FPM: só os ajustes do BarberFlow; o docker.conf e o zz-docker.conf oficiais continuam valendo
COPY docker/php/php-fpm.d/zz-barberflow.conf /usr/local/etc/php-fpm.d/zz-barberflow.conf
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/php-fpm-healthcheck
RUN php-fpm -t

HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=3 CMD ["php-fpm-healthcheck"]

# ---------------------------------------------------------------------------
# dev: base + Composer, para o docker-compose.yml com o código montado do host
# ---------------------------------------------------------------------------
FROM base AS dev

# unzip: o Composer usa para abrir os pacotes baixados (--prefer-dist)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# No dev o código muda o tempo todo, então o OPcache volta a conferir a data dos arquivos
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zzz-dev.ini
COPY --chmod=0755 docker/php/entrypoint-dev.sh /usr/local/bin/entrypoint-dev

ENTRYPOINT ["entrypoint-dev"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# vendor: dependências de produção do Composer (sem as de dev, como Pail e Collision)
# ---------------------------------------------------------------------------
FROM composer AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# O autoload otimizado lista as classes de app/ e database/, então elas entram antes do dump
COPY app/ app/
COPY database/ database/
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------------------------------------------------------------------------
# assets: CSS e JS do Vite (public/build)
# ---------------------------------------------------------------------------
FROM node:24.22.3-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

# O laravel-vite-plugin não chama o PHP no build. Hoje o vite.config.js baixa a fonte
# Instrument Sans da internet (bunny), então este estágio precisa de rede.
COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build

# ---------------------------------------------------------------------------
# web: Nginx com o public/ e o public/build
# ---------------------------------------------------------------------------
FROM nginx:1.30.6-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/html/public/
COPY --from=assets /app/public/build/ /var/www/html/public/build/

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8081/nginx_status || exit 1

# ---------------------------------------------------------------------------
# app: produção. Código, vendor e public/build dentro da imagem, rodando como www-data.
# Fica por último para ser o alvo padrão de um "docker build" sem --target.
# ---------------------------------------------------------------------------
FROM base AS app

COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/entrypoint

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor/ vendor/
COPY --chown=www-data:www-data --from=assets /app/public/build/ public/build/

USER www-data

# Gera o bootstrap/cache/packages.php a partir do vendor de produção. Se o cache do dev
# (que lista Pail e Collision) entrasse na imagem, o build quebraria aqui, e não na EC2;
# o .dockerignore deixa esse cache de fora.
RUN php artisan package:discover --ansi

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
