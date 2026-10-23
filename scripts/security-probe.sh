#!/usr/bin/env bash
# Sondagem de segurança da Academy e do Learning (etapa 2.7b). Ver docs/development/fase-2-etapa-2.7-plano.md.
#
#   E2E_KEEP=1 make e2e          # sobe a stack E2E descartável (e mantém no ar)
#   ./scripts/security-probe.sh  # roda a sondagem dentro do container web dessa stack
#   make e2e-down                # derruba a stack e descarta os dados
#
# Só funciona contra a stack E2E (projeto Compose cs-tactical-e2e): o script roda dentro do
# container web dela e só fala com http://localhost:3000 dali. Nunca toca o ambiente de
# desenvolvimento nem produção. A senha das contas de teste vem das fixtures, não deste arquivo.
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

PASSWORD=$(sed -n 's/^export const E2E_PASSWORD = "\(.*\)"$/\1/p' apps/web/e2e/support/accounts.ts)
if [[ -z "$PASSWORD" ]]; then
  echo "Senha das contas E2E não encontrada em apps/web/e2e/support/accounts.ts" >&2
  exit 2
fi

compose exec -T -e PROBE_PASSWORD="$PASSWORD" web node --input-type=module - < scripts/security-probe.mjs
