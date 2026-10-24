#!/usr/bin/env bash
# Linha de base de desempenho (etapa 2.7d) numa stack Docker descartável. docs/development/desempenho.md
#
#   ./scripts/perf.sh                 # sobe a stack E2E nova, mede os dois cenários, derruba tudo
#   PERF_SAMPLES=30 ./scripts/perf.sh # mais amostras por endpoint (padrão 20)
#   PERF_KEEP=1 ./scripts/perf.sh     # mantém a stack no ar ao final (derrube com: make e2e-down)
#   PERF_OPCACHE=0 ./scripts/perf.sh  # API sem OPcache, como a imagem de desenvolvimento
#
# Usa o projeto Compose da stack E2E (cs-tactical-e2e: Postgres e Redis em tmpfs, build de produção
# do Next); não roda junto com `make e2e`. Nunca toca a stack de desenvolvimento. Cenários:
# - pequeno: o conteúdo de E2E (apps/web/e2e/fixtures/academy);
# - ampliado: + 50 cursos × 5 módulos × 8 aulas gerados dentro do container (decisão R3).
# Resultados em .perf/<data-hora>/ (fora do Git): JSON brutos, ambiente e summary.md.
set -euo pipefail

cd "$(dirname "$0")/.."

PROJECT=cs-tactical-e2e
# PERF_OPCACHE=0 mede como a imagem de desenvolvimento (sem OPcache), para comparação.
OVERRIDES=(-f compose.yaml -f compose.e2e.yaml)
[[ "${PERF_OPCACHE:-1}" == "1" ]] && OVERRIDES+=(-f scripts/perf/compose.opcache.yaml)
compose() {
  docker compose -p "$PROJECT" "${OVERRIDES[@]}" --profile e2e-runner "$@"
}

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source <(grep -E '^HOST_(UID|GID)=' .env)
  set +a
fi
export HOST_UID="${HOST_UID:-$(id -u)}" HOST_GID="${HOST_GID:-$(id -g)}"

PASSWORD=$(sed -n 's/^export const E2E_PASSWORD = "\(.*\)"$/\1/p' apps/web/e2e/support/accounts.ts)
if [[ -z "$PASSWORD" ]]; then
  echo "Senha das contas E2E não encontrada em apps/web/e2e/support/accounts.ts" >&2
  exit 2
fi
if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 é necessário para o resumo" >&2
  exit 2
fi

OUT=".perf/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$OUT"

environment() {
  {
    echo "## $1"
    echo "data: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "commit: $(git rev-parse --short HEAD)$(git diff --quiet || echo ' (com alterações locais)')"
    echo "OPcache da API: $([[ "${PERF_OPCACHE:-1}" == "1" ]] && echo ligado || echo 'desligado (imagem de desenvolvimento)')"
    echo "amostras por endpoint: ${PERF_SAMPLES:-20}"
    echo "nproc: $(nproc)"
    echo "memória: $(free -m | awk '/^Mem:/ {print $2 " MB total, " $7 " MB disponível"}')"
    echo "uptime: $(uptime)"
    echo
  } >> "$OUT/ambiente.md"
}

cleanup() {
  local status=$?
  if [[ "${PERF_KEEP:-}" == "1" ]]; then
    echo "• PERF_KEEP=1: stack mantida. Derrube com: make e2e-down"
  else
    echo "• Derrubando a stack (dados descartados)"
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT

measure() {
  local scenario=$1
  echo "  · HTTP: API e páginas (alguns minutos, espaçado para respeitar os limites)"
  compose exec -T -e PERF_PASSWORD="$PASSWORD" -e PERF_SCENARIO="$scenario" -e PERF_SAMPLES="${PERF_SAMPLES:-20}" \
    web node --input-type=module - < scripts/perf/http.mjs > "$OUT/$scenario-http.json"
  compose logs --no-color --timestamps api > "$OUT/$scenario-api-access.log" 2>/dev/null
  echo "  · Consultas por endpoint e EXPLAIN (espera a janela de 1 min dos limites virar)"
  sleep 61
  compose exec -T -e PERF_SCENARIO="$scenario" -e PERF_EXPLAIN=1 api php < scripts/perf/api-queries.php > "$OUT/$scenario-queries.json"
}

environment "antes"
echo "1/5 Subindo uma stack E2E nova (o build de produção do frontend leva alguns minutos)"
compose down --volumes --remove-orphans >/dev/null 2>&1 || true
compose up -d --wait postgres redis mailpit api worker web

echo "2/5 Cenário pequeno: banco E2E, conteúdo de E2E e jogadores de medição"
compose exec -T api php artisan e2e:prepare
compose exec -T api php artisan academy:import /var/www/e2e-content/academy >/dev/null
compose exec -T -e PERF_SCENARIO=pequeno api php < scripts/perf/seed-players.php

echo "3/5 Medindo o cenário pequeno"
measure pequeno

echo "4/5 Cenário ampliado: 2.000 aulas geradas no container, importadas, e histórico do jogador"
compose exec -T -e PERF_DIR=/tmp/perf-academy api php < scripts/perf/generate-academy.php
compose exec -T api php artisan academy:import /tmp/perf-academy >/dev/null
compose exec -T -e PERF_SCENARIO=ampliado api php < scripts/perf/seed-players.php
measure ampliado

environment "depois"
echo "5/5 Resumo"
python3 scripts/perf/summary.py "$OUT" | tee "$OUT/summary.md"
echo "• Resultados em $OUT"
