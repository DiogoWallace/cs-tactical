#!/usr/bin/env bash
# Verifica pré-requisitos do ambiente local antes de subir os serviços.
set -uo pipefail

cd "$(dirname "$0")/.."

ok()   { printf '  \033[32m✔\033[0m %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✘\033[0m %s\n' "$1"; failures=$((failures + 1)); }
failures=0

echo "Verificando ambiente do CS Tactical"

case "$(pwd)" in
  /mnt/*) fail "Repositório em $(pwd). Mova para o sistema de arquivos do WSL (ex.: ~/projects/cs-tactical)." ;;
  *)      ok "Repositório fora de /mnt (sistema de arquivos Linux)." ;;
esac

if grep -qi microsoft /proc/version 2>/dev/null; then
  ok "Executando dentro do WSL."
else
  warn "Não parece ser WSL. Tudo deve funcionar em Linux nativo, mas o projeto é testado no WSL2."
fi

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  ok "Docker disponível ($(docker version --format '{{.Server.Version}}' 2>/dev/null))."
else
  fail "Docker indisponível. Abra o Docker Desktop e ative a integração WSL para esta distribuição."
fi

if docker compose version >/dev/null 2>&1; then
  ok "Docker Compose disponível ($(docker compose version --short))."
else
  fail "Docker Compose v2 não encontrado."
fi

if [[ -f .env ]]; then
  ok ".env encontrado."
  # shellcheck disable=SC1091
  set -a; source .env; set +a
  if [[ "${HOST_UID:-}" != "$(id -u)" ]]; then
    warn "HOST_UID (${HOST_UID:-vazio}) difere do seu UID ($(id -u)). Rode 'make setup'."
  fi
else
  warn ".env ainda não existe. Ele é criado pelo 'make setup'."
fi

if [[ "$(git config --get core.autocrlf 2>/dev/null)" == "true" ]]; then
  warn "git core.autocrlf=true. Recomendado no WSL: git config --global core.autocrlf false"
fi

running="$(docker compose ps --services --status running 2>/dev/null || true)"
check_port() {
  local name="$1" port="$2" service="$3"
  if grep -qx "$service" <<<"$running"; then
    ok "Porta ${port} (${name}) em uso pelo próprio projeto."
  elif (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
    fail "Porta ${port} (${name}) já está em uso. Altere ${name} no .env."
  else
    ok "Porta ${port} (${name}) livre."
  fi
}
check_port WEB_PORT        "${WEB_PORT:-3000}"        web
check_port API_PORT        "${API_PORT:-8000}"        api
check_port MAILPIT_UI_PORT "${MAILPIT_UI_PORT:-8025}" mailpit

if (( failures > 0 )); then
  echo "${failures} problema(s) encontrado(s)."
  exit 1
fi
echo "Tudo pronto."
