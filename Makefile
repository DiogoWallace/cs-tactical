# CS Tactical — comandos do ambiente de desenvolvimento.
# Rode `make` ou `make help` para ver a lista.

SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

COMPOSE := docker compose
API     := $(COMPOSE) exec api
WEB     := $(COMPOSE) exec web

##@ Ambiente

.PHONY: help
help: ## Lista os comandos disponíveis
	@awk 'BEGIN {FS = ":.*##"; printf "\nUso: make \033[36m<comando>\033[0m\n"} \
		/^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

.PHONY: setup
setup: ## Prepara o ambiente: .env, UID/GID, build, dependências, APP_KEY
	@./scripts/setup.sh

.PHONY: doctor
doctor: ## Verifica Docker, WSL, .env e portas livres
	@./scripts/doctor.sh

.PHONY: up
up: ## Sobe os serviços e aguarda ficarem saudáveis
	$(COMPOSE) up -d --wait

.PHONY: down
down: ## Derruba os serviços (mantém os dados)
	$(COMPOSE) down

.PHONY: restart
restart: down up ## Reinicia os serviços

.PHONY: ps
ps: ## Mostra o estado dos serviços
	$(COMPOSE) ps

.PHONY: logs
logs: ## Acompanha logs (ex.: make logs s=postgres)
	$(COMPOSE) logs -f --tail=100 $(s)

.PHONY: build
build: ## Reconstrói as imagens locais
	$(COMPOSE) build

##@ API (Laravel)

.PHONY: sh-api
sh-api: ## Abre um shell no container da API
	$(API) bash

.PHONY: artisan
artisan: ## Executa um comando artisan (ex.: make artisan c="route:list")
	$(API) php artisan $(c)

.PHONY: composer
composer: ## Executa o Composer (ex.: make composer c="require pacote")
	$(API) composer $(c)

.PHONY: migrate
migrate: ## Executa as migrations pendentes (nunca roda automaticamente)
	$(API) php artisan migrate

.PHONY: fresh
fresh: ## Recria o banco de dev do zero com seeders. Pede confirmação
	@read -r -p "Isso apaga e recria o banco de desenvolvimento. Digite 'recriar' para confirmar: " ans; \
		[[ "$$ans" == "recriar" ]] || { echo "Cancelado."; exit 1; }
	$(API) php artisan migrate:fresh --seed

.PHONY: check-infra
check-infra: ## Enfileira um job que envia um e-mail de teste ao Mailpit
	$(API) php artisan system:check-infra

.PHONY: tinker
tinker: ## Abre o Tinker
	$(API) php artisan tinker

##@ Web (Next.js)

.PHONY: sh-web
sh-web: ## Abre um shell no container do frontend
	$(WEB) bash

.PHONY: pnpm
pnpm: ## Executa o pnpm (ex.: make pnpm c="add pacote")
	$(WEB) pnpm $(c)

##@ Qualidade

.PHONY: test
test: test-api test-web ## Roda todos os testes

.PHONY: test-api
test-api: ## Testes da API (Pest, banco cs_tactical_test)
	$(API) composer test

.PHONY: test-web
test-web: ## Testes do frontend (Vitest)
	$(WEB) pnpm test

.PHONY: lint
lint: lint-api lint-web ## Roda todas as verificações de estilo e análise estática

.PHONY: lint-api
lint-api: ## Pint (modo verificação) + Larastan
	$(API) composer lint

.PHONY: lint-web
lint-web: ## ESLint + Prettier (verificação) + TypeScript
	$(WEB) pnpm lint
	$(WEB) pnpm format:check
	$(WEB) pnpm typecheck

.PHONY: fmt
fmt: fmt-api fmt-web ## Formata o código automaticamente

.PHONY: fmt-api
fmt-api: ## Formata o PHP com Pint
	$(API) composer fmt

.PHONY: fmt-web
fmt-web: ## Formata o frontend com Prettier
	$(WEB) pnpm format

##@ Dados

.PHONY: psql
psql: ## Abre o psql no banco de desenvolvimento
	$(COMPOSE) exec postgres sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

.PHONY: redis-cli
redis-cli: ## Abre o redis-cli
	$(COMPOSE) exec redis redis-cli

.PHONY: destroy
destroy: ## APAGA containers e volumes (banco e Redis). Pede confirmação
	@read -r -p "Isso apaga TODOS os dados locais (Postgres e Redis). Digite 'apagar' para confirmar: " ans; \
		[[ "$$ans" == "apagar" ]] || { echo "Cancelado."; exit 1; }
	$(COMPOSE) down --volumes --remove-orphans
