#!/bin/sh
# Остановка веб-сервера B4-04 (фолбэк, если Ctrl+C не дошёл: убьёт php -S в контейнере).
# Вызов: sh /app/scripts/docker-web-stop.sh  (докер-контейнер: make web-stop)
set -e
pkill -f "php -S 0.0.0.0:8080" 2>/dev/null || true
echo "[B4-04] веб-сервер остановлен (если был запущен)"