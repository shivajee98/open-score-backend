#!/bin/sh
set -e

# Run migrations (force force to run in production)
php artisan migrate --force --seed
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start php-fpm
php-fpm
