#!/usr/bin/env pwsh

Write-Host "Starting Laravel setup..."

# Run migrations
Write-Host "Running migrations..."
php artisan migrate:fresh --seed

Write-Host "Setup complete!"
php artisan list --short
