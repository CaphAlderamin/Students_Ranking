#!/bin/sh
# Запуск PHPStan level max внутри контейнера php (B1-03). Вызов: sh /app/scripts/docker-stan.sh
set -e
cd /app
vendor/bin/phpstan analyse