# Conciliador Migrator - Development Dockerfile
FROM hyperf/hyperf:8.4-alpine-v3.21-swoole
LABEL maintainer="DevCC Team" version="1.0" app.name="conciliador-migrator-dev"

ARG timezone=America/Sao_Paulo
ARG UID=1000
ARG GID=1000

# RUN cp .env.production .env
# RUN sed -i'' -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env

ENV TIMEZONE=${timezone} \
    APP_ENV=prod \
    SCAN_CACHEABLE=(true)

RUN addgroup -g ${GID} application && \
    adduser -S -D -u ${UID} -G application -s /bin/ash -h /home/application application

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

RUN chmod +x /usr/local/bin/composer

# USER application

WORKDIR /opt/www

COPY . /opt/www
RUN composer install && php bin/hyperf.php

EXPOSE 9501

ENTRYPOINT ["php", "/opt/www/bin/hyperf.php", "start"]
