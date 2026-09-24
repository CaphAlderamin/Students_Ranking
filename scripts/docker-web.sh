#!/bin/sh
# Веб-сервер B4-04: встроенный php -S с docroot public/ (минимальный веб, stack/client.md).
# Вызов: sh /app/scripts/docker-web.sh  (докер-контейнер: make web, http://127.0.0.1:8080/)
# Остановка: Ctrl+C в make web; фолбэк — make web-stop.
set -u

echo "[B4-04] веб-сервер: http://127.0.0.1:8080/ (Ctrl+C для остановки)"

# Гасим предыдущие экземпляры (после аварийного Ctrl+C они держат порт 8080).
pkill -f "php -S 0.0.0.0:8080" 2>/dev/null || true
sleep 1

cd /app

# php -S в фоне + wait: сценарий переживает сигналы INT/TERM и передаёт их серверу,
# поэтому Ctrl+C (make web) корректно останавливает и контейнерный php -S.
php -S 0.0.0.0:8080 -t public &
PID=$!

cleanup() {
    kill -TERM "$PID" 2>/dev/null || true
    wait "$PID" 2>/dev/null || true
}
trap cleanup INT TERM

wait "$PID"
exit 0