# Hamro Ward — developer commands
.PHONY: help up down reset-db logs ps psql install api web fresh tenants-migrate test lint format types

COMPOSE = docker compose -f infra/compose/compose.dev.yml

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start Postgres/PostGIS and Mailpit
	$(COMPOSE) up -d
	@echo "Postgres :5432 (hw_central, hw_central_test)  ·  Mailpit http://localhost:8025"

down: ## Stop the local stack (keeps data)
	$(COMPOSE) down

reset-db: ## Destroy local databases and re-run the role/init script
	$(COMPOSE) down -v
	$(COMPOSE) up -d

logs: ## Tail the local stack
	$(COMPOSE) logs -f

ps: ## Show local stack status
	$(COMPOSE) ps

psql: ## psql shell on hw_central
	$(COMPOSE) exec db psql -U hw_owner -d hw_central

install: ## Install PHP and JS dependencies
	cd apps/api && composer install
	pnpm install

api: ## Laravel API on :8000
	cd apps/api && php artisan serve --host=127.0.0.1 --port=8000

web: ## Next.js app on :3000
	pnpm --filter web dev

fresh: ## Rebuild the central schema, seed, and migrate every tenant database
	cd apps/api && php artisan migrate:fresh --seed && php artisan hw:tenant:migrate

tenants-migrate: ## Migrate all tenant databases
	cd apps/api && php artisan hw:tenant:migrate

test: ## Run all tests
	cd apps/api && ./vendor/bin/pest
	pnpm --filter web test -- --run

lint: ## Style and static analysis
	cd apps/api && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G
	pnpm --filter web lint
	pnpm --filter web exec tsc --noEmit

format: ## Fix formatting
	cd apps/api && ./vendor/bin/pint
	pnpm --filter web exec prettier --write "src/**/*.{ts,tsx,css}"

types: ## Regenerate the OpenAPI client (HW-E01-F04-T02)
	@echo "Not wired yet — task HW-E01-F04-T02"
