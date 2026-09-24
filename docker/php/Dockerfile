# Образ CLI-окружения PHP (стек: php:8.3-cli-alpine)

FROM php:8.3-cli-alpine

# git и unzip нужны composer'у для установки зависимостей;
# oniguruma-dev — библиотека для расширения mbstring;
# $PHPIZE_DEPS — компилятор для pecl (pcov, B3-04): устанавливается и удаляется.
RUN apk add --no-cache git unzip oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql mbstring \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

# pcov.enabled включается адресно при запуске покрытия (scripts/docker-coverage.sh),
# чтобы обычный прогон тестов не платил за инструментирование.
RUN printf 'pcov.enabled=0\n' > /usr/local/etc/php/conf.d/zz-pcov.ini

# Composer 2.x из официального образа
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app