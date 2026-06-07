FROM php:8.2-cli

# Installer dépendances système + extensions PHP nécessaires
RUN apt-get update && apt-get install -y \
    git unzip zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        zip \
        pdo \
        pdo_mysql \
        gd

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