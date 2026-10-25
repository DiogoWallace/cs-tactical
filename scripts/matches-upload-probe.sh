#!/usr/bin/env bash
# Sondagem do envio de demos pelo proxy do Next (etapa M3). Ver docs/training/mvp-m3-envio-de-demos.md.
#
#   E2E_KEEP=1 make e2e                # sobe a stack E2E descartável (e mantém no ar)
#   ./scripts/matches-upload-probe.sh  # envia um arquivo sintético em partes de 8 MiB e confere o disco
#   make e2e-down                      # derruba a stack e descarta os dados
#
# Só funciona contra a stack E2E (projeto Compose cs-tactical-e2e). A senha da conta de teste vem
# das fixtures, não deste arquivo. Rodar uma vez por stack: a conta tem cota de 5 envios por dia.
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

OUTPUT=$(compose exec -T -e PROBE_PASSWORD="$PASSWORD" web node --input-type=module - < scripts/matches-upload-probe.mjs) || STATUS=$?
echo "$OUTPUT" | grep -v '^UPLOAD '

read -r _ ID SHA < <(echo "$OUTPUT" | grep '^UPLOAD ' || echo "UPLOAD - -")
DIR=/var/www/html/storage/app/matches-uploads/uploads
FILES=$(compose exec -T api sh -c "ls $DIR 2>/dev/null" </dev/null | tr '\n' ' ')
DISK_SHA=$(compose exec -T api sh -c "sha256sum $DIR/$ID.dem 2>/dev/null | cut -d' ' -f1" </dev/null)

if [[ "$DISK_SHA" == "$SHA" ]]; then
  echo "ok   M8 arquivo no disco da API com o mesmo SHA-256 do enviado"
else
  echo "FAIL M8 SHA-256 no disco ($DISK_SHA) diferente do enviado ($SHA)"
  STATUS=1
fi
if [[ "$FILES" == "$ID.dem " ]]; then
  echo "ok   M9 no disco só o arquivo concluído (o cancelado e a parte recusada não ficaram)"
else
  echo "FAIL M9 arquivos no disco: $FILES"
  STATUS=1
fi

exit "${STATUS:-0}"
