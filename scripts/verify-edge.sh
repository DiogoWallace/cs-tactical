#!/usr/bin/env bash
# Verificação externa dos bloqueios P1, P2 e P4 contra um ambiente publicado (sem deploy aqui).
# Rode de FORA da rede do servidor:
#
#   ./scripts/verify-edge.sh https://cstactical.example [email-de-teste@dominio]
#
# O e-mail (opcional) deve ser de uma conta de teste: o script faz 6 logins com senha errada para
# conferir o rate limit (a conta fica limitada por 1 minuto). docs/deploy/bloqueios-de-producao.md
set -uo pipefail

BASE="${1:?uso: $0 https://dominio [email-de-teste]}"
EMAIL="${2:-}"
BASE="${BASE%/}"
HOST="$(echo "$BASE" | sed -E 's#^https?://##; s#/.*$##')"
fails=0
pass() { echo "ok     $*"; }
fail() { echo "FALHA  $*"; fails=$((fails + 1)); }

echo "== P1/P4: TLS, HSTS e headers"
if [[ "$BASE" != https://* ]]; then fail "a URL precisa ser https"; fi
headers="$(curl -sS -D - -o /dev/null "$BASE/login")"
grep -qi '^strict-transport-security:' <<<"$headers" && pass "HSTS presente" || fail "HSTS ausente na borda"
grep -qi '^content-security-policy:' <<<"$headers" && pass "CSP presente" || fail "CSP ausente"
grep -qi '^x-powered-by:' <<<"$headers" && fail "X-Powered-By exposto" || pass "sem X-Powered-By"
http_code="$(curl -sS -o /dev/null -w '%{http_code}' "http://$HOST/")"
[[ "$http_code" =~ ^30[18]$ ]] && pass "HTTP redireciona para HTTPS ($http_code)" || fail "HTTP não redireciona (status $http_code)"

echo "== P4: cookies e erros"
jar="$(mktemp)"; trap 'rm -f "$jar"' EXIT
cookies="$(curl -sS -c "$jar" -D - -o /dev/null -H "Referer: $BASE/" "$BASE/sanctum/csrf-cookie" | grep -i '^set-cookie:')"
grep -qi 'secure' <<<"$cookies" && pass "cookies com Secure" || fail "cookies sem Secure"
health="$(curl -sS "$BASE/api/v1/health")"
grep -q '"checks"' <<<"$health" && fail "health expõe detalhes" || pass "health sem detalhes"
notfound="$(curl -sS "$BASE/api/v1/rota-inexistente")"
grep -Eqi 'trace|exception|vendor/' <<<"$notfound" && fail "erro expõe detalhes internos" || pass "erro sem detalhes internos"

if [[ -n "$EMAIL" ]]; then
  echo "== P1: X-Forwarded-For forjado não burla o rate limit de login"
  xsrf="$(awk '$6=="XSRF-TOKEN"{print $7}' "$jar" | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')"
  last=""
  for i in 1 2 3 4 5 6; do
    last="$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
      -H "Accept: application/json" -H "Content-Type: application/json" \
      -H "Origin: $BASE" -H "Referer: $BASE/" -H "X-XSRF-TOKEN: $xsrf" \
      -H "X-Forwarded-For: 203.0.113.$i" -H "X-Real-IP: 203.0.113.$i" -H "Forwarded: for=203.0.113.$i" \
      -d "{\"email\":\"$EMAIL\",\"password\":\"senha-errada-verificacao\"}" "$BASE/api/v1/auth/login")"
  done
  [[ "$last" == "429" ]] && pass "6ª tentativa com IP forjado -> 429" || fail "6ª tentativa respondeu $last (esperado 429): a borda não substitui o X-Forwarded-For"
else
  echo "(informe um e-mail de conta de teste para verificar o rate limit com IP forjado)"
fi

echo "== P2: portas expostas (só 80 e 443 da borda)"
if command -v nmap >/dev/null; then
  open="$(nmap -Pn -p 1-65535 --open -T4 "$HOST" 2>/dev/null | awk '/^[0-9]+\/tcp/{print $1}' | tr '\n' ' ')"
  extra="$(tr ' ' '\n' <<<"$open" | grep -Ev '^(80|443)/tcp$' | grep -v '^$' | tr '\n' ' ')"
  [[ -z "$extra" ]] && pass "só 80/443 abertas" || fail "portas além de 80/443: $extra"
else
  echo "(instale o nmap para verificar as portas; sem ele, confira o firewall manualmente)"
fi

echo
[[ "$fails" == 0 ]] && echo "Nenhuma falha encontrada." || echo "$fails falha(s): o deploy público continua bloqueado."
exit $(( fails > 0 ))
