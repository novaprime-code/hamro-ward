# Hamro Ward — developer commands
.PHONY: help up down build wait-db reset-db logs ps shell psql psql-tenant \
        install web fresh demo demo-drop tenant-create tenants-migrate \
        sync-reference test lint format check types

COMPOSE = docker compose -f infra/compose/compose.dev.yml

# Passed into the image build so www-data matches you and bind-mounted files
# stay writable. Named HW_UID rather than UID because UID is read-only in bash
# and exporting it from make is unreliable.
HW_UID ?= $(shell id -u)
HW_GID ?= $(shell id -g)
export HW_UID
export HW_GID

# One-shot commands use `run --rm`, not `exec`. It costs about a second per
# call and works whether or not the app container is up — which matters most
# on a fresh clone, where `composer install` has to run before the container
# can stay running at all.
#
# PHP_ISO adds --no-deps for commands that touch no service. Without it,
# `composer install` starts Postgres, and an unrelated registry or database
# problem breaks dependency installation for no reason.
PHP     = $(COMPOSE) run --rm app
PHP_ISO = $(COMPOSE) run --rm --no-deps app
ARTISAN = $(PHP) php artisan

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---- stack ------------------------------------------------------------------

build: ## Build the development PHP image
	$(COMPOSE) build app

up: ## Start Postgres, Mailpit and the API (:8000)
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory wait-db
	@echo "API http://localhost:8000  ·  Mailpit http://localhost:8025  ·  Postgres :5432"

down: ## Stop the local stack (keeps data)
	$(COMPOSE) down

wait-db: ## Block until Postgres accepts connections
	@printf "waiting for postgres"
	@until $(COMPOSE) exec -T db pg_isready -U hw_owner -q 2>/dev/null; do \
		printf "."; sleep 1; \
	done; echo " ready"

reset-db: ## Destroy local databases and re-run the role/init script
	$(COMPOSE) down -v
	$(COMPOSE) up -d db mailpit
	@$(MAKE) --no-print-directory wait-db

logs: ## Tail the local stack
	$(COMPOSE) logs -f

ps: ## Show local stack status
	$(COMPOSE) ps

shell: ## Shell inside the PHP container
	$(PHP) bash

psql: ## psql shell on the central database
	$(COMPOSE) exec db psql -U hw_owner -d hw_central

psql-tenant: ## psql on one tenant database: make psql-tenant DB=hw_t_5f3a9c1e
	@test -n "$(DB)" || (echo "usage: make psql-tenant DB=hw_t_xxxxxxxx" && exit 1)
	$(COMPOSE) exec db psql -U hw_owner -d $(DB)

# ---- dependencies and processes ---------------------------------------------

install: build ## Install PHP (container) and JS (host) dependencies
	$(PHP_ISO) composer install
	pnpm install

web: ## Next.js app on :3000 — runs on the host, Node is fine natively
	pnpm --filter web dev

# ---- database ---------------------------------------------------------------

# Order is load-bearing, and getting it wrong is silent:
#
#  1. Demo tenants are dropped FIRST, while the tenants registry still exists.
#     migrate:fresh wipes that table but leaves the hw_t_* databases on disk,
#     so doing this second orphans them permanently.
#  2. The rebuild names the connection and the path. The default connection is
#     hw_app, which does not own the tables, and central migrations do not live
#     in the default directory — without both flags this drops nothing and
#     rebuilds nothing.
#  3. Reference data before demo data: the demo seeder copies the positions
#     catalogue into each tenant it creates, so the reverse order produces four
#     municipalities with no seats and no error.
fresh: ## Rebuild central from zero, re-seed reference and demonstration data
	-$(ARTISAN) hw:demo:drop --force
	$(ARTISAN) migrate:fresh --force \
		--database=central_owner \
		--path=database/migrations/central
	$(ARTISAN) db:seed --force
	$(ARTISAN) hw:demo:seed --force

demo: ## Seed (or refresh) the four demonstration municipalities
	$(ARTISAN) hw:demo:seed --force

demo-drop: ## Drop the demonstration tenants, keep provinces and districts
	$(ARTISAN) hw:demo:drop --force

tenant-create: ## Onboard a local level: make tenant-create SLUG=koshi/sunsari/koshara
	@test -n "$(SLUG)" || (echo "usage: make tenant-create SLUG=province/district/local-level" && exit 1)
	$(ARTISAN) hw:tenant:create $(SLUG)

tenants-migrate: ## Migrate all tenant databases
	$(ARTISAN) hw:tenant:migrate

sync-reference: ## Refresh catalogue and geography replicas in every tenant
	$(ARTISAN) hw:tenant:sync-reference

# ---- quality ----------------------------------------------------------------

test: ## Run all tests
	$(PHP) ./vendor/bin/pest
	pnpm --filter web test -- --run

lint: ## Style and static analysis
	$(PHP_ISO) ./vendor/bin/pint --test
	$(PHP_ISO) ./vendor/bin/phpstan analyse --memory-limit=1G
	pnpm --filter web lint
	pnpm --filter web exec tsc --noEmit

format: ## Fix formatting
	$(PHP_ISO) ./vendor/bin/pint

# The web build is not redundant with lint and test. It has caught unresolved
# imports, a bad type predicate, a server/client boundary violation and a
# missing component — all of which lint and vitest passed, and all of which
# would otherwise have surfaced in a Docker build minutes later.
check: ## Everything CI runs. Do this before pushing
	@$(MAKE) --no-print-directory lint
	pnpm --filter web build
	@$(MAKE) --no-print-directory test

types: ## Regenerate the OpenAPI client (HW-E01-F04-T02)
	@echo "Not wired yet — task HW-E01-F04-T02"