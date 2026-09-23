#!/bin/sh
# Веб-сервер B4-04: встроенный php -S с docroot public/ (минимальный веб, stack/client.md).
# Вызов: sh /app/scripts/docker-web.sh  (докер-контейнер: make web)
set -e
cd /app
php -S 0.0.0.0:8080 -t public