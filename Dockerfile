FROM php:8.3-apache

LABEL org.opencontainers.image.title="GridSight"
LABEL org.opencontainers.image.description="Vizualizace energetické bilance FVE – PHP + SQLite"
LABEL org.opencontainers.image.url="https://github.com/hacesoft/GridSight"
LABEL org.opencontainers.image.source="https://github.com/hacesoft/GridSight"
LABEL org.opencontainers.image.licenses="GPL-3.0"

# ── PHP rozšíření ─────────────────────────────────────────────────
# ── PHP rozšíření ─────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_sqlite gd

# ── Composer ──────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Apache: document root = /var/www/html/public ─────────────────
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri 's|/var/www/html|/var/www/html/public|g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf && \
    a2enmod rewrite headers && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

# ── PHP konfigurace ───────────────────────────────────────────────
RUN echo "upload_max_filesize = 32M"  >> /usr/local/etc/php/conf.d/gridsight.ini && \
    echo "post_max_size = 32M"        >> /usr/local/etc/php/conf.d/gridsight.ini && \
    echo "max_execution_time = 120"   >> /usr/local/etc/php/conf.d/gridsight.ini && \
    echo "memory_limit = 256M"        >> /usr/local/etc/php/conf.d/gridsight.ini

# ── Aplikace ──────────────────────────────────────────────────────
WORKDIR /var/www/html

COPY php/composer.json php/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY php/ ./

# Data volume
RUN mkdir -p /data && chown www-data:www-data /data
VOLUME ["/data"]

ENV DATA_DIR=/data

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
  CMD curl -f http://localhost/api.php?action=range || exit 1
