FROM php:8.2-cli

# Installer dépendances système
RUN apt-get update && apt-get install -y \
    git unzip zip libzip-dev \
    && docker-php-ext-install zip pdo pdo_mysql

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copier le projet
COPY . .

# Installer dépendances Symfony
RUN composer install --no-dev --optimize-autoloader

# Port Render
EXPOSE 10000

# Lancer Symfony
CMD php -S 0.0.0.0:10000 -t public