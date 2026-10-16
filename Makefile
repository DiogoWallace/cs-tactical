# CS Tactical — comandos do ambiente de desenvolvimento.
# Rode `make` ou `make help` para ver a lista.

SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

COMPOSE := docker compose

##@ Ambiente

.PHONY: help
help: ## Lista os comandos disponíveis
	@awk 'BEGIN {FS = ":.*##"; printf "\nUso: make \033[36m<comando>\033[0m\n"} \
		/^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

.PHONY: setup
setup: ## Prepara o ambiente (cria .env, ajusta UID/GID)
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
