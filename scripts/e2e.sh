#!/usr/bin/env bash
# Roda os testes E2E (Playwright) numa stack Docker isolada e descartável. docs/development/e2e.md
#
#   ./scripts/e2e.sh                         # sobe a stack, prepara o banco, testa, derruba tudo
#   ./scripts/e2e.sh --project=chromium-desktop specs/login.spec.ts   # argumentos vão ao Playwright
#   E2E_KEEP=1 ./scripts/e2e.sh              # mantém a stack no ar ao final (para depurar)
#
# Nunca toca a stack de desenvolvimento: projeto Compose, rede e volumes próprios (cs-tactical-e2e),
# Postgres e Redis em tmpfs, e o comando de preparo só existe com APP_ENV=e2e.
set -euo pipefail

cd "$(dirname "$0")/.."

PROJECT=cs-tactical-e2e
compose() {
  docker compose -p "$PROJECT" -f compose.yaml -f compose.e2e.yaml --profile e2e-runner "$@"
}

if [[ -f .env ]]; then
  # HOST_UID/HOST_GID: o Playwright grava relatórios em apps/web/e2e com o seu usuário.
  set -a
  # shellcheck disable=SC1091
  source <(grep -E '^HOST_(UID|GID)=' .env)
  set +a
fi
export HOST_UID="${HOST_UID:-$(id -u)}" HOST_GID="${HOST_GID:-$(id -g)}"

cleanup() {
  local status=$?
  if [[ "$status" != "0" ]]; then
    # Logs dos serviços junto dos resultados do Playwright (vão para os artefatos da CI).
    mkdir -p apps/web/e2e/.results
    compose logs --no-color --timestamps > apps/web/e2e/.results/stack.log 2>&1 || true
    echo "• Falhou. Relatório: make e2e-report | logs da stack: apps/web/e2e/.results/stack.log"
  fi
  if [[ "${E2E_KEEP:-}" == "1" ]]; then
    echo "• E2E_KEEP=1: stack mantida. Derrube com: make e2e-down"
  else
    echo "• Derrubando a stack E2E (dados descartados)"
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT

echo "1/4 Subindo a stack E2E (o build de produção do frontend leva alguns minutos)"
compose up -d --wait postgres redis mailpit api worker web

echo "2/4 Recriando o banco E2E"
compose exec -T api php artisan e2e:prepare

echo "3/4 Rodando o Playwright"
rm -rf apps/web/e2e/.results apps/web/e2e/.report
compose run --rm --no-deps playwright npx playwright test "$@"

echo "4/4 Testes E2E concluídos"
