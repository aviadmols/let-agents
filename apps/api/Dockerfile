# One image, three roles on Railway: web (Octane on FrankenPHP), worker (queues), scheduler.
# The role comes from the APP_ROLE variable each Railway service sets. See docs/runbooks/deploy-railway.md.
#
# Build context is the repository root, so the image can also pack the store plugin:
#   docker build -f apps/api/Dockerfile .

FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, so a code-only change reuses this layer.
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY apps/api/ .

# The event spec the beacon endpoint validates against (Analytics\Support\BeaconSchema).
COPY packages/event-spec/schema resources/event-spec

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# The downloadable WooCommerce plugin, built from the same commit as the app that serves it.
COPY plugins/woocommerce /tmp/let-agents-plugin
RUN php /tmp/let-agents-plugin/bin/build.php \
    && mkdir -p resources/plugins \
    && cp /tmp/let-agents-plugin/dist/let-agents-*.zip resources/plugins/ \
    && rm -rf /tmp/let-agents-plugin

COPY apps/api/docker/php.ini "$PHP_INI_DIR/conf.d/zz-upsell.ini"
COPY apps/api/docker/entrypoint.sh /usr/local/bin/upsell-entrypoint
RUN chmod +x /usr/local/bin/upsell-entrypoint

EXPOSE 8080

ENTRYPOINT ["upsell-entrypoint"]
