FROM php:8.3-cli

# Postgres client libs + the pdo_pgsql extension this app requires (includes/db.php)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Render (and Railway/Heroku) inject the port to listen on via $PORT.
# router.php already mirrors the app's .htaccess rewrite rules for PHP's
# built-in server, so no separate Apache/Nginx config is needed.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} router.php"]
