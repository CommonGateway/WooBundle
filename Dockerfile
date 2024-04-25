FROM ghcr.io/conductionnl/commonground-gateway-php:dev AS common_gateway

USER root

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer update commongateway/corebundle --no-scripts
RUN composer require common-gateway/woo-bundle --no-scripts

COPY src vendor/common-gateway/woo-bundle/src
COPY composer.json vendor/common-gateway/woo-bundle/composer.json

RUN composer install --no-scripts --no-plugins

USER commonground-gateway

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]