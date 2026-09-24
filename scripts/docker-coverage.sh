#!/bin/sh
# Прогон PHPUnit с измерением покрытия ядра распределения (pcov, B3-04).
# Вызов: sh /app/scripts/docker-coverage.sh (make coverage).
# Фильтр — только src/Application/Distribution; pcov включается адресно,
# чтобы обычный прогон (make test) не платил за инструментирование.
set -e
cd /app
php -d pcov.enabled=1 vendor/bin/phpunit \
    --coverage-filter src/Application/Distribution \
    --coverage-text