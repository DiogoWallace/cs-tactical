#!/usr/bin/env bash
# Reprodução controlada do limite `academy-public` compartilhado na stack E2E (etapa 2.7b, S9).
# Ver a seção "Limite academy-public na stack E2E" de docs/development/fase-2-etapa-2.7-plano.md.
#
#   E2E_KEEP=1 make e2e                  # stack E2E descartável no ar
#   ./scripts/academy-public-repro.sh    # reproduz e mostra a cadeia de IPs no log da API
#   make e2e-down
#
# Só lê o catálogo como visitante. Espere 60 s antes de rodar outra coisa contra a mesma stack:
# o limite esgotado vale por um minuto.
set -euo pipefail

cd "$(dirname "$0")/.."

PROJECT=cs-tactical-e2e
compose() {
  docker compose -p "$PROJECT" -f compose.yaml -f compose.e2e.yaml "$@"
}

if [[ "$(compose ps --status running --services 2>/dev/null | grep -cx web)" != "1" ]]; then
  echo "A stack E2E não está no ar. Rode antes: E2E_KEEP=1 make e2e" >&2
  exit 2
fi

SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
compose exec -T web node --input-type=module - < scripts/academy-public-repro.mjs

echo
echo "Log de acesso da API desde ${SINCE} (cliente que a API vê → status, por X-Forwarded-For recebido):"
compose logs --no-color --since "$SINCE" api 2>/dev/null \
  | grep -E '"GET /api/v1/academy/courses[ ?]' \
  | awk '{status=$11; xff=$NF; print "  conexão de " $3 " | XFF " xff " | " status}' \
  | sort | uniq -c
