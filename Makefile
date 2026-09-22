# Hamro Ward — developer commands
.PHONY: help up down logs api web install fresh test lint format types ps psql

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start Postgres/PostGIS and Mailpit
	docker compose -f infra/compose/compose.dev.yml up -d
	@echo "Postgres :5432 (hw_central)  ·  Mailpit http://localhost:8025"

down: ## Stop the local stack
	docker compose -f infra/compose/compose.dev.yml down

logs: ## Tail the local stack
	docker compose -f infra/compose/compose.dev.yml logs -f

ps: ## Show local stack status
	docker compose -f infra/compose/compose.dev.yml ps

psql: ## Open a psql shell on hw_central
	docker compose -f infra/compose/compose.dev.yml exec db psql -U hw_owner -d hw_central

install: ## Install PHP and JS dependencies
	cd apps/api && composer install
	pnpm install

api: ## Run the Laravel API on :8000
	cd apps/api && php artisan serve --host=127.0.0.1 --port=8000

web: ## Run the Next.js app on :3000
	pnpm --filter web dev

fresh: ## Recreate the central schema and seed
	cd apps/api && php artisan migrate:fresh --seed

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

types: ## Regenerate the OpenAPI client (added in HW-E01-F04-T02)
	@echo "Not wired yet — task HW-E01-F04-T02"
