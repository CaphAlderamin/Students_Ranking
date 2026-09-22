#!/bin/sh
# Запуск SQL-миграций (B1-05): вызывает PDO-раннер scripts/migrate.php в php-контейнере.
set -e

if [ -z "$(find /app/database/migrations -maxdepth 1 -name '*.sql' 2>/dev/null | head -n 1)" ]; then
    echo "[B1-05] миграции не найдены: создайте database/migrations/*.sql (ошибка конфигурации)" >&2
    exit 1
fi

exec php /app/scripts/migrate.php