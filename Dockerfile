FROM php:8.3-fpm-alpine

# System packages
RUN apk add --no-cache nginx git openssh-client curl libzip-dev mariadb mariadb-client

# PHP extensions
RUN docker-php-ext-install mysqli zip

# Install typst
RUN ARCH="$(uname -m)" && \
    curl -fsSL "https://github.com/typst/typst/releases/download/v0.14.2/typst-${ARCH}-unknown-linux-musl.tar.xz" \
    | tar -xJ --strip-components=1 -C /usr/local/bin "typst-${ARCH}-unknown-linux-musl/typst" && \
    ln -s /usr/local/bin/typst /bin/typst

# MariaDB config (enable TCP networking)
COPY docker/mariadb.cnf /etc/my.cnf.d/network.cnf

# nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.conf   /etc/nginx/php.conf

# PHP settings (upload limits, error handling, sessions)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# php-fpm pool config (replaces default www.conf)
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Application code
COPY --chown=www-data:www-data . /var/www/html

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/reaper.sh /reaper.sh
RUN chmod +x /entrypoint.sh /reaper.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s \
    CMD curl -fsS -o /dev/null http://127.0.0.1/css/dark_mode.php || exit 1

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
