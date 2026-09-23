#!/bin/sh
# Запуск сидера (B2-03): вызывает PDO-раннер scripts/seed.php в php-контейнере.
# Базовый каталог B2-02 + краевые когорты (CohortCatalog/EdgeSeeder): assertInvariant()
# выполняется в раннере на PHP и отбрасывает детерминированное исключение при нарушении.
set -e

if [ ! -f /app/vendor/autoload.php ]; then
    echo "[B2-03] vendor/autoload.php отсутствует: выполните composer install (ошибка конфигурации)" >&2
    exit 1
fi

if [ ! -f /app/config/db.php ]; then
    echo "[B2-03] config/db.php отсутствует: скопируйте config/db.php.example (ошибка конфигурации)" >&2
    exit 1
fi

exec php /app/scripts/seed.php