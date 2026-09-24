# Students Ranking — Makefile (B1-02)
# Кроссплатформенный: рецепты рассчитаны на cmd.exe (Windows) и sh (Linux/CI).
# Запрещены inline-команды с кавычками (sh -lc '...'); многоступенчатая логика — в scripts/*.sh.

ifeq ($(OS),Windows_NT)
    SHELL := cmd.exe
endif

COMPOSE := docker compose
PHP_EXEC := $(COMPOSE) exec -T php

PHPUNIT := $(wildcard vendor/bin/phpunit)
PHPSTAN := $(wildcard vendor/bin/phpstan)

.PHONY: up down restart composer-install migrate seed reset-db reset-db-migrate reset-db-migrate-seed distribute web web-stop test stan coverage dbml

help:
	@echo "Available commands:"
	@echo "  up            - Start the services"
	@echo "  down          - Stop the services"
	@echo "  restart       - Restart the services"
	@echo "  composer-install - Install Composer dependencies"
	@echo "  migrate       - Run database migrations"
	@echo "  seed          - Seed the database"
	@echo "  reset-db      - Reset the database"
	@echo "  reset-db-migrate - Reset the database and run migrations"
	@echo "  reset-db-migrate-seed - Reset the database, run migrations, and seed"
	@echo "  distribute    - Run distribution via bin/console (ALGO=date|criteria, FMT=tsv reserved for B4)"
	@echo "  web           - Run web UI (http://127.0.0.1:8080/, dev server php -S :8080, docroot public/, B4-04)"
	@echo "  web-stop      - Stop web UI server in container (fallback if Ctrl+C missed)"
	@echo "  test          - Run tests"
	@echo "  stan          - Run PHPStan"
	@echo "  stan-test     - Run PHPStan and tests"
	@echo "  coverage      - Run tests with code coverage (pcov)"
	@echo "  dbml          - Install DBML tools"
	@echo "  mysql         - Run MySQL shell"

up:
	@$(COMPOSE) up -d --build

down:
	@$(COMPOSE) down

restart:
	@$(COMPOSE) restart

composer-install:
	@$(COMPOSE) up -d
	@$(PHP_EXEC) composer install

migrate:
	@$(PHP_EXEC) sh /app/scripts/migrate.sh

seed:
	@$(PHP_EXEC) sh /app/scripts/seed.sh

reset-db:
	@$(PHP_EXEC) sh /app/scripts/db-reset.sh

reset-db-migrate:
	@$(PHP_EXEC) sh /app/scripts/db-reset.sh migrate

reset-db-migrate-seed:
	@$(PHP_EXEC) sh /app/scripts/db-reset.sh seed

ALGO ?= date
FMT ?= tsv

distribute:
	@$(PHP_EXEC) php bin/console distribute --algorithm=$(ALGO)

web:
	@$(COMPOSE) up -d
	@$(COMPOSE) exec php sh /app/scripts/docker-web.sh

web-stop:
	@$(PHP_EXEC) sh /app/scripts/docker-web-stop.sh

test:
	@$(if $(PHPUNIT),$(PHP_EXEC) sh /app/scripts/docker-test.sh,echo [B1-03] phpunit не установлен: ожидается в B1-03)

stan:
	@$(if $(PHPSTAN),$(PHP_EXEC) sh /app/scripts/docker-stan.sh,echo [B1-03] phpstan не установлен: ожидается в B1-03)

stan-test: stan test

coverage:
	@$(if $(PHPUNIT),$(PHP_EXEC) sh /app/scripts/docker-coverage.sh,echo [B3-04] phpunit не установлен: ожидается в B1-03)

dbml:
	@npm install --prefix tools/dbml --no-audit --no-fund --silent
	@node tools/dbml/render.cjs

mysql:
	@$(COMPOSE) exec -it mysql mysql --default-character-set=utf8mb4 -u root -p
