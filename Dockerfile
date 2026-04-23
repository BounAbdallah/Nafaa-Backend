FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    mysql-client \
    nginx \
    nodejs \
    npm \
    oniguruma-dev \
    openssl \
    supervisor \
    unzip \
    zip

# Install PHP extensions
RUN docker-php-ext-install \
    bcmath \
    exif \
    gd \
    mbstring \
    pdo \
    pdo_mysql \
    pcntl \
    xml \
    zip

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist

# Copy application code
COPY . .

# Generate optimized autoload
RUN composer dump-autoload --optimize

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
