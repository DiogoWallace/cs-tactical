#!/usr/bin/env bash
# Alarme do limite `academy-public` na stack E2E (etapa 2.7b, decisão R1). docs/development/e2e.md
#
#   compose logs --no-color api | ./scripts/e2e-academy-peak.sh 172.31.251.10
#
# Lê o log de acesso do Nginx da API (stdin) e mede, por chave do limite, o pico de leituras das
# três rotas do catálogo em qualquer janela de 60 s. Só mede e avisa: não muda limite, proxy nem
# teste, e nunca falha a execução. O argumento é o TRUSTED_PROXIES da stack.
#
# A chave é reproduzida como o Laravel faz com `$request->ip()`: o IP da conexão, ou, se a conexão
# vem de um proxy confiável, o último endereço não confiável do X-Forwarded-For.
set -euo pipefail

if ! command -v python3 >/dev/null 2>&1; then
  echo "• academy-public: python3 ausente, pico não medido"
  exit 0
fi

# A fonte vai por -c: o stdin do Python é o log.
python3 -c "$(cat <<'PY'
import os, re, sys
from collections import Counter, defaultdict, deque
from datetime import datetime

LIMIT = 120     # Limit::perMinute(120) do AcademyServiceProvider
WARN_AT = 90    # 75% do limite

trusted = {p.strip() for p in sys.argv[1].split(",") if p.strip()}
route = re.compile(r"^/api/v1/academy/courses(/[^/?]+(/lessons/[^/?]+)?)?/?(\?.*)?$")
line_re = re.compile(
    r'(?P<addr>\S+) - \S+ \[(?P<time>[^\]]+)\] "(?P<method>\S+) (?P<path>\S+) [^"]*" '
    r'(?P<status>\d{3}) \S+ "[^"]*" "[^"]*" "(?P<xff>[^"]*)"'
)

def key(addr, xff):
    if addr not in trusted or xff in ("", "-"):
        return addr
    for hop in reversed([h.strip() for h in xff.split(",")]):
        if hop not in trusted:
            return hop
    return addr

hits = defaultdict(list)
statuses = Counter()
for line in sys.stdin:
    m = line_re.search(line)
    if not m or m["method"] != "GET" or not route.match(m["path"]):
        continue
    t = datetime.strptime(m["time"], "%d/%b/%Y:%H:%M:%S %z").timestamp()
    hits[key(m["addr"], m["xff"])].append(t)
    statuses[m["status"]] += 1

total = sum(statuses.values())
if total == 0:
    print("• academy-public: nenhuma leitura do catálogo no log da API")
    sys.exit(0)

peaks = {}
for k, times in hits.items():
    window, best = deque(), 0
    for t in sorted(times):
        window.append(t)
        while t - window[0] >= 60:
            window.popleft()
        best = max(best, len(window))
    peaks[k] = best

worst_key, worst = max(peaks.items(), key=lambda kv: kv[1])
by_key = ", ".join(f"{k}={v}" for k, v in sorted(peaks.items(), key=lambda kv: -kv[1]))
summary = (f"academy-public: pico {worst}/{LIMIT} em 60 s (chave {worst_key}); "
           f"{total} leituras, 429={statuses.get('429', 0)}; por chave: {by_key}")
print(f"• {summary}")

if worst >= WARN_AT or statuses.get("429", 0):
    msg = (f"{summary}. Acima de {WARN_AT}: a suíte está perto do limite compartilhado; "
           "ver docs/development/fase-2-etapa-2.7-plano.md#limite-academy-public-na-stack-e2e")
    print(f"• ALERTA {msg}")
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print(f"::warning title=academy-public::{msg}")
PY
)" "${1:-}"
