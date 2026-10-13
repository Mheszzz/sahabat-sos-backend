#!/bin/bash
set -e

echo "⏳ Menunggu database PostgreSQL di ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
while ! nc -z ${DB_HOST:-postgres} ${DB_PORT:-5432}; do
  sleep 1
done
echo "✅ Database PostgreSQL siap!"

# Pastikan symlink storage public ada
if [ ! -L public/storage ]; then
    echo "🔗 Menghubungkan public storage symlink..."
    php artisan storage:link || true
fi

exec "$@"
