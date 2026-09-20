# Образ CLI-окружения PHP (стек: php:8.3-cli-alpine)

FROM php:8.3-cli-alpine

# git и unzip нужны composer'у для установки зависимостей;
# oniguruma-dev — библиотека для расширения mbstring
RUN apk add --no-cache git unzip oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring

# Composer 2.x из официального образа
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app