.DEFAULT_GOAL := help

.PHONY: dev build logs debug wp shell help

dev: ## Start the dev stack
	pnpm dev

build: ## Build all workspace apps
	pnpm build

logs: ## Tail wordpress logs
	docker compose logs -f wordpress

debug: ## Tail debug logs
	docker compose exec wordpress tail -f wp-content/debug.log

wp: ## Run a WP-CLI command, e.g. make wp post list
	docker compose exec wordpress wp $(filter-out $@,$(MAKECMDGOALS))

shell: ## Open a bash shell in the wordpress container
	docker compose exec wordpress bash

help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

%:
	@:
