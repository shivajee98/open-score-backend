FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    nginx

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy existing application directory permissions
# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction


# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Create invalidation file for artisan commands (optional)
RUN touch /var/www/storage/logs/laravel.log
RUN chmod -R 777 /var/www/storage

# Expose ports
EXPOSE 80 8080

# Start Supervisor
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
