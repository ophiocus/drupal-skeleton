# syntax=docker/dockerfile:1
#
# Production image — Drupal 11, composer-managed, Apache, behind a
# reverse proxy (Traefik). Ships with the skeleton so every project minted
# from it builds a deployable image from its first commit (PROTOCOL D17).
#
# Contract (see docs/DEPLOY.md):
#   - core/, modules/contrib/, themes/contrib/ and vendor/ are NOT in git;
#     composer resolves them here from the committed composer.lock.
#   - web/sites/default/ is NOT in git; deploy/settings.prod.php becomes
#     settings.php inside the image and reads everything from the
#     environment (deploy/.env.example lists the keys).
#   - Docroot is /opt/drupal/web; drush is on PATH; files live on volumes.
#
# Base image pin: `drupal:11-php8.3-apache`, never the floating
# `drupal:11-apache` meta-tag (PROTOCOL D6) — the explicit tag follows 8.3.x
# patch releases but will not silently cross to PHP 8.4.

# ─── Stage 1: resolve and scaffold the Drupal project ────────────────
FROM drupal:11-php8.3-apache AS build

WORKDIR /opt/drupal

# PHP extensions and OS packages that the drupal base image does NOT ship
# but a real build needs. DDEV's web image *does* have all of these, which
# is exactly why their absence only ever surfaces in CI or in production:
#   - git + unzip     composer's download path. Dist zips come from
#                     codeload.github.com, which rate-limits by IP (HTTP
#                     429); composer then falls back to a git clone of the
#                     source — and without git in the image that fallback
#                     dies with "git was not found in your PATH" and the
#                     whole build fails on a transient throttle. unzip
#                     avoids the slower PHP-zip extraction path (and its
#                     "permissions will be lost" warning) for the dist case.
#   - bcmath          Drupal Commerce (commerce_price does exact decimal
#                     money arithmetic with it). Without it `composer
#                     install` fails its platform check the day Commerce
#                     lands. Cheap; install it up front.
#   - mariadb-client  `drush sql:dump / sql:drop / sql:cli` shell out to
#                     `mysql`. Without the client drush prints "shell
#                     command 'mysql' is required" and silently bails —
#                     which once left a half-populated DB mid-redeploy.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j"$(nproc)" bcmath

# The base image ships its own Drupal project here; replace it with ours.
RUN rm -rf /opt/drupal/web /opt/drupal/vendor \
           /opt/drupal/composer.json /opt/drupal/composer.lock

# Dependencies first — cached layer, invalidated only when the lock moves.
#
# Prod incantation: no dev deps; non-interactive + no progress chatter for
# clean CI logs; prefer-dist for zip archives over git clones; optimized
# autoloader (classmap from vendor + project PSR-4 paths).
#
# NOTE: --classmap-authoritative is INCOMPATIBLE with Drupal. Core's
# modules/* (mysql/pgsql/sqlite drivers, field types, module Plugin/*
# classes) are NOT in composer's autoload manifest — Drupal discovers and
# autoloads them at runtime via its own ContainerNamespaceLoader. With
# --classmap-authoritative composer's autoloader refuses to fall back to a
# PSR-4 lookup, so the runtime loader never gets a chance:
#   Class "Drupal\mysql\Driver\Database\mysql\Connection" not found
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --optimize-autoloader \
 && rm -rf /root/.composer/cache

# Application source on top. Everything composer-managed, DDEV-only, or
# secret is excluded by .dockerignore — keep that file honest when you add
# a top-level directory. (Copying the whole tree, rather than an explicit
# COPY per directory, means a new `web/<thing>/` or `recipes/` lands in the
# image without anyone remembering to add a line here.)
COPY . /opt/drupal/

# core-composer-scaffold has already generated web/index.php, .htaccess,
# etc. during `composer install`; COPY . above cannot clobber them because
# those files are gitignored and therefore absent from the build context.

# ─── Stage 2: runtime ────────────────────────────────────────────────
FROM drupal:11-php8.3-apache

WORKDIR /opt/drupal

# Runtime needs the same extensions/packages as the build stage.
RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j"$(nproc)" bcmath

# ─── Platform tuning: identical in every property ────────────────────
# Source: this file; change it here first, then copy the block verbatim into
# every property. Why each line: the platform's performance audit.
#   opcache  a Drupal codebase is 17–20k PHP files; the base image caches
#            4,000 and recompiles the rest on every request. The code is
#            baked into this image, so timestamps are never re-checked.
#   APCu     Drupal moves its bootstrap, config and discovery caches into
#            APCu (chained to the database) as soon as the extension loads.
#   memory   1024M for the CLI (drush deploy, recipes); 512M per web request.
#   prefork  20 workers: one peaks near 70 MiB, the container has 2 GiB.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install apcu; \
    docker-php-ext-enable apcu; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    printf '%s\n' \
      'opcache.memory_consumption=256' \
      'opcache.interned_strings_buffer=32' \
      'opcache.max_accelerated_files=32531' \
      'opcache.validate_timestamps=0' \
      'apc.shm_size=128M' \
      'memory_limit=1024M' \
      > /usr/local/etc/php/conf.d/zz-platform-tuning.ini; \
    printf '%s\n' \
      '<IfModule mpm_prefork_module>' \
      '    StartServers             3' \
      '    MinSpareServers          2' \
      '    MaxSpareServers          6' \
      '    MaxRequestWorkers       20' \
      '    MaxConnectionsPerChild 500' \
      '</IfModule>' \
      > /etc/apache2/mods-available/mpm_prefork.conf; \
    printf '%s\n' \
      '<IfModule php_module>' \
      '    php_value memory_limit 512M' \
      '</IfModule>' \
      > /etc/apache2/conf-enabled/zz-platform-php.conf; \
    php -m | grep -qx apcu; \
    apache2ctl -t

RUN rm -rf /opt/drupal/web /opt/drupal/vendor
COPY --from=build --chown=www-data:www-data /opt/drupal /opt/drupal

# Docroot is web/; the base image serves /var/www/html.
RUN rm -rf /var/www/html && ln -sf /opt/drupal/web /var/www/html

# The repo cannot ship web/sites/default/ (gitignored), so the tracked
# production settings file becomes settings.php inside the image.
RUN cp /opt/drupal/deploy/settings.prod.php \
       /opt/drupal/web/sites/default/settings.php \
 && chown www-data:www-data /opt/drupal/web/sites/default/settings.php

# Public + private files must be writable by the web user. Both are volume
# mount points in deploy/docker-compose.yaml; creating them here means a
# first boot without the volumes still serves. NOTE: a bind mount whose
# host directory does not exist yet is created root:root by Docker and
# overrides this chown — deploy/entrypoint.sh re-asserts ownership of the
# mount points on every start, so first deploy needs no manual chown.
RUN mkdir -p /opt/drupal/web/sites/default/files /opt/drupal/private \
 && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private

COPY deploy/entrypoint.sh /usr/local/bin/drupal-entrypoint
RUN chmod 0755 /usr/local/bin/drupal-entrypoint
ENTRYPOINT ["drupal-entrypoint"]
CMD ["apache2-foreground"]

# drush on PATH for `docker compose exec drupal drush …` and deploy hooks.
ENV PATH="/opt/drupal/vendor/bin:${PATH}"

EXPOSE 80
