#!/bin/sh
# Сброс базы данных (микрозап-патч 2026-09-23): DROP всех таблиц + фазы.
# Моды: без аргумента = только сброс; migrate = сброс + миграции;
#       seed = сброс + миграции + сидер (каталог + когорты + заявки).
# Миграции и сидер вызываются как подпроцессы — откат при ошибке любого шага (set -e).
set -e

if [ ! -f /app/vendor/autoload.php ]; then
    echo "[db-reset] vendor/autoload.php отсутствует: выполните composer install (см. stack/server.md)" >&2
    exit 1
fi

if [ ! -f /app/config/db.php ]; then
    echo "[db-reset] config/db.php отсутствует: скопируйте config/db.php.example (см. stack/server.md)" >&2
    exit 1
fi

exec php /app/scripts/db-reset.php "$@"