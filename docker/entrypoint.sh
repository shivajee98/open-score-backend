#!/bin/sh
set -e

# Run migrations (force force to run in production)
php artisan migrate --force --seed

# Start php-fpm
php-fpm
