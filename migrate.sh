#!/bin/bash
# Script to run migrations on Hostinger
echo "Starting migrations..."
php artisan migrate --force
echo "Migrations completed!"
