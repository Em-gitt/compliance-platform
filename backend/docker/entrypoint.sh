#!/bin/sh
set -e

cd /var/www/html

# Asegurar directorios de almacenamiento y permisos
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

# Si se pasan argumentos explícitos (ej: php artisan migrate o comandos de CI/dev), ejecutarlos directamente
if [ "$1" != "serve-and-work" ] && [ $# -gt 0 ]; then
    exec "$@"
fi

# Eliminar cualquier archivo de cache residual antes de descubrir paquetes
rm -f bootstrap/cache/*.php

# Descubrimiento de paquetes y limpieza de caché con variables de entorno de producción
php artisan package:discover --ansi || true
php artisan config:clear || true
php artisan route:clear || true

PORT="${PORT:-8000}"

# Manejo ordenado de señales SIGTERM y SIGINT
cleanup() {
    echo "🛑 Recibida señal de terminación (SIGTERM/SIGINT). Deteniendo procesos..."
    if [ -n "$WORKER_PID" ]; then
        kill -TERM "$WORKER_PID" 2>/dev/null || true
    fi
    if [ -n "$WEB_PID" ]; then
        kill -TERM "$WEB_PID" 2>/dev/null || true
    fi
    wait 2>/dev/null || true
    exit 0
}

trap cleanup TERM INT

echo "🚀 Iniciando Laravel Queue Worker supervisado (cola: documents)..."
(
    while true; do
        php artisan queue:work redis \
            --queue=documents \
            --sleep=3 \
            --tries=3 \
            --timeout=300 \
            --max-jobs=50 \
            --memory=128 || true
        sleep 1
    done
) &
WORKER_PID=$!

echo "🚀 Iniciando Servidor Web Laravel en el puerto ${PORT}..."
php artisan serve --host=0.0.0.0 --port="${PORT}" --no-reload &
WEB_PID=$!

wait "$WEB_PID"
