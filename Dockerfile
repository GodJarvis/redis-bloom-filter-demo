ARG PHP_BASE_IMAGE=php:cli-alpine
FROM ${PHP_BASE_IMAGE}

ARG REDIS_EXTENSION_VERSION=6.3.0

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    pecl install redis-${REDIS_EXTENSION_VERSION}; \
    docker-php-ext-enable redis; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    apk del .build-deps; \
    rm -rf /tmp/pear; \
    php --ri redis; \
    php --ri pdo_mysql

WORKDIR /demo
ENTRYPOINT ["php"]
CMD ["demo.php", "help"]
