#!/bin/bash
# Script to run migrations on Hostinger
# Ensure we are in the script's directory
cd "$(dirname "$0")"
echo "Changing directory to $(pwd)"
echo "Clearing caches..."
php artisan optimize:clear
echo "Starting migrations..."
php artisan migrate --force
echo " optimizing..."
php artisan optimize
