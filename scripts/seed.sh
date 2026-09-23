#!/bin/sh
# Запуск сидера (B2-02): вызывает PDO-раннер scripts/seed.php в php-контейнере.
set -e

if [ ! -f /app/vendor/autoload.php ]; then
    echo "[B2-02] vendor/autoload.php отсутствует: выполните composer install (ошибка конфигурации)" >&2
    exit 1
fi

if [ ! -f /app/config/db.php ]; then
    echo "[B2-02] config/db.php отсутствует: скопируйте config/db.php.example (ошибка конфигурации)" >&2
    exit 1
fi

exec php /app/scripts/seed.php