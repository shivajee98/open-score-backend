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
    nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# --- COMPOSER CACHE STEP ---
# Copy only composer files first to leverage Docker cache
COPY composer.json composer.lock ./

# Install dependencies without scripts/autoloader first (very fast if files haven't changed)
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction
# --- END COMPOSER CACHE STEP ---

# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www

# Finalize composer (run scripts and generate optimized autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Fix permissions for storage and bootstrap/cache
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache && \
    touch storage/logs/laravel.log && \
    chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

# Expose ports
EXPOSE 80 8080

# Start Supervisor
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
