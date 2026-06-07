FROM php:8.2-cli

# Installer dépendances système + extensions PHP nécessaires
RUN apt-get update && apt-get install -y \
    git unzip zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        intl \
        zip \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        gd

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Définir les valeurs par défaut Render
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PORT=10000

# Copier le projet
COPY . .

# Installer dépendances Symfony et construire l’application en mode production
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction \
    && composer dump-env prod --no-interaction \
    && php bin/console cache:warmup --env=prod --no-debug

# Port Render
EXPOSE 10000

# Lancer Symfony
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]