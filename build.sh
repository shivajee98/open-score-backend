#!/bin/bash
set -e

echo "Running Composer install..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "Generating app key if needed..."
php artisan key:generate --force || true

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Build complete!"
