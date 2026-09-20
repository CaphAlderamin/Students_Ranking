# Students Ranking — Makefile (B1-02)
# Кроссплатформенный: рецепты рассчитаны на cmd.exe (Windows) и sh (Linux/CI).
# Запрещены inline-команды с кавычками (sh -lc '...'); многоступенчатая логика — в scripts/*.sh.

ifeq ($(OS),Windows_NT)
    SHELL := cmd.exe
endif

COMPOSE := docker compose
PHP_EXEC := $(COMPOSE) exec -T php

SCHEMA_DBML := $(wildcard database/schema.dbml)
MIGRATIONS := $(wildcard database/migrations/*.sql)
PHPUNIT := $(wildcard vendor/bin/phpunit)
PHPSTAN := $(wildcard vendor/bin/phpstan)

.PHONY: up down restart composer-install migrate seed distribute test stan dbml

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
	@$(if $(MIGRATIONS),$(PHP_EXEC) sh /app/scripts/migrate.sh,echo [B1-05] миграции не найдены: появятся в B1-05)

seed:
	@echo [branch-2] seed не реализован: наполнение в branch-2

ALGO ?= date
FMT ?= tsv

distribute:
	@echo [branch-3] distribute не реализован: наполнение в branch-3 (ALGO=$(ALGO), FMT=$(FMT))

test:
	@$(if $(PHPUNIT),$(PHP_EXEC) sh /app/scripts/docker-test.sh,echo [B1-03] phpunit не установлен: ожидается в B1-03)

stan:
	@$(if $(PHPSTAN),$(PHP_EXEC) sh /app/scripts/docker-stan.sh,echo [B1-03] phpstan не установлен: ожидается в B1-03)

dbml:
	@$(if $(SCHEMA_DBML),npx --yes -p @dbml/cli dbml2mermaid $(SCHEMA_DBML) > docs/schema.mmd,echo [B1-04] database/schema.dbml не создан: ожидается в B1-04)