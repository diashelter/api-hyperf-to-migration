# Conciliador Migrator - Production Dockerfile
FROM hyperf/hyperf:8.4-alpine-v3.21-swoole
LABEL maintainer="DevCC Team" version="1.0" app.name="conciliador-migrator"

ARG timezone=America/Sao_Paulo

ENV TIMEZONE=${timezone} \
    APP_ENV=prod \
    SCAN_CACHEABLE=(true)

RUN set -ex \
    && apk add --no-cache php84-pgsql \
    && php -v \
    && php -m \
    && php --ri swoole \
    && cd /etc/php* \
    && { \
        echo "upload_max_filesize=128M"; \
        echo "post_max_size=128M"; \
        echo "memory_limit=1G"; \
        echo "date.timezone=${TIMEZONE}"; \
    } | tee conf.d/99_overrides.ini \
    && ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man

WORKDIR /opt/www

COPY composer.json composer.lock /opt/www/
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . /opt/www
RUN composer dump-autoload -o && php bin/hyperf.php

EXPOSE 9501

ENTRYPOINT ["php", "/opt/www/bin/hyperf.php", "start"]
