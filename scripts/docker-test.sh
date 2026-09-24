#!/bin/sh
# Запуск PHPUnit внутри контейнера php (B1-03). Вызов: sh /app/scripts/docker-test.sh
set -e
cd /app
vendor/bin/phpunit