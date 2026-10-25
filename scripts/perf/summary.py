"""Resumo da medição de desempenho (etapa 2.7d) em Markdown. Ver docs/development/desempenho.md.

    python3 scripts/perf/summary.py .perf/<data-hora>

Lê os JSON e logs gravados pelo scripts/perf.sh e imprime: ambiente, dados de cada cenário,
consultas e tempos por endpoint, páginas (TTFB, fim do streaming, chamadas à API) e as consultas
mais lentas do EXPLAIN no cenário ampliado. Só biblioteca padrão.
"""

import json
import math
import re
import sys
from datetime import datetime
from pathlib import Path

SCENARIOS = ("pequeno", "ampliado")
# Horário gravado pelo Nginx (fim da requisição, resolução de segundo), não o de chegada ao Docker,
# que pode atrasar mais de um segundo e jogar a chamada na janela da página seguinte.
LOG_LINE = re.compile(r'\[(\d{2}/\w{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\] "(GET|POST|PUT|DELETE) (/api/\S+|/sanctum\S*) HTTP')


def load(out: Path, name: str):
    path = out / name
    return json.loads(path.read_text()) if path.exists() else None


def parse_ts(value: str) -> datetime:
    # Docker grava nanossegundos; o datetime aceita até microssegundos.
    value = re.sub(r"(\.\d{6})\d*", r"\1", value.replace("Z", "+00:00"))
    return datetime.fromisoformat(value)


def calls_per_window(out: Path, scenario: str, windows: list) -> dict:
    log = out / f"{scenario}-api-access.log"
    if not log.exists():
        return {}
    entries = []
    for line in log.read_text(errors="replace").splitlines():
        match = LOG_LINE.search(line)
        if match:
            ts = datetime.strptime(match.group(1), "%d/%b/%Y:%H:%M:%S %z").timestamp()
            entries.append((ts, match.group(2), match.group(3).split("?")[0]))
    counted = {}
    for window in windows:
        start = int(parse_ts(window["from"]).timestamp())
        end = math.ceil(parse_ts(window["to"]).timestamp())
        hits = [f"{method} {path}" for ts, method, path in entries if start <= ts <= end]
        counted[window["name"]] = hits
    return counted


def cell(value, suffix=""):
    return "—" if value is None else f"{value}{suffix}"


def main() -> None:
    out = Path(sys.argv[1])
    data = {s: {"q": load(out, f"{s}-queries.json"), "h": load(out, f"{s}-http.json")} for s in SCENARIOS}

    print(f"# Medição de desempenho — {out.name}\n")
    env = out / "ambiente.md"
    if env.exists():
        print(env.read_text().replace("## ", "### "))

    print("## Dados\n")
    print("| Tabela | " + " | ".join(SCENARIOS) + " |\n|---|" + "---|" * len(SCENARIOS))
    keys = next((d["q"]["data"].keys() for d in data.values() if d["q"]), [])
    for key in keys:
        print(f"| {key} | " + " | ".join(cell(data[s]["q"] and data[s]["q"]["data"].get(key)) for s in SCENARIOS) + " |")
    print()

    print("## API\n")
    print("Consultas e tempo em processo: `api-queries.php` (kernel HTTP, jogador autenticado como no `actingAs`).")
    print("HTTP: `http.mjs` direto na API (Nginx + PHP-FPM), p50/p95 do tempo total em ms.\n")
    print("| Endpoint | Consultas P | Consultas A | Em processo P | Em processo A | HTTP p50 P | HTTP p95 P | HTTP p50 A | HTTP p95 A | Status |")
    print("|---|---|---|---|---|---|---|---|---|---|")
    names = []
    for s in SCENARIOS:
        q = data[s]["q"] or {}
        for name in list(q.get("reads", {})) + list(q.get("writes", {})):
            if name not in names:
                names.append(name)
    problems = []
    for name in names:
        row = [name]
        counts = []
        for s in SCENARIOS:
            q = data[s]["q"] or {}
            entry = q.get("reads", {}).get(name) or q.get("writes", {}).get(name) or {}
            counts.append(entry.get("queries"))
            if entry.get("status", 200) >= 400:
                problems.append(f"{s}/{name}: consultas medidas com status {entry['status']}")
        row += [cell(c) for c in counts]
        for s in SCENARIOS:
            q = data[s]["q"] or {}
            entry = q.get("reads", {}).get(name) or q.get("writes", {}).get(name) or {}
            row.append(cell(entry.get("in_process_ms_median", entry.get("in_process_ms"))))
        statuses = []
        for s in SCENARIOS:
            h = (data[s]["h"] or {}).get("api", {}).get(name)
            row += [cell(h and h.get("total", {}).get("p50")), cell(h and h.get("total", {}).get("p95"))]
            if h:
                statuses.append(f"{s[0].upper()}:" + ",".join(f"{k}×{v}" for k, v in h["statuses"].items()))
                if any(not k.startswith("2") for k in h["statuses"]):
                    problems.append(f"{s}/{name}: HTTP {h['statuses']}")
        row.append(" ".join(statuses) or "—")
        if None not in counts and len(set(counts)) > 1:
            problems.append(f"{name}: consultas {counts[0]} (pequeno) ≠ {counts[1]} (ampliado)")
        print("| " + " | ".join(str(c) for c in row) + " |")
    print()

    print("## Páginas do Next\n")
    print("`http.mjs` em http://localhost:3000 (build de produção): TTFB e fim do corpo (streaming) em ms, p50/p95;")
    print("chamadas à API feitas pelo servidor do Next numa carga isolada da página (log de acesso da API).\n")
    print("| Página | TTFB P | Total P | Chamadas P | TTFB A | Total A | Chamadas A |")
    print("|---|---|---|---|---|---|---|")
    calls = {s: calls_per_window(out, s, (data[s]["h"] or {}).get("windows", [])) for s in SCENARIOS}
    pages = []
    for s in SCENARIOS:
        for name in (data[s]["h"] or {}).get("pages", {}):
            if name not in pages:
                pages.append(name)
    for name in pages:
        row = [name]
        for s in SCENARIOS:
            p = (data[s]["h"] or {}).get("pages", {}).get(name)
            row.append(f"{p['ttfb']['p50']} / {p['ttfb']['p95']}" if p else "—")
            row.append(f"{p['total']['p50']} / {p['total']['p95']}" if p else "—")
            hits = calls[s].get(name)
            row.append(f"{len(hits)} ({', '.join(sorted(set(h.split(' ')[1] for h in hits)))})" if hits is not None else "—")
            if p and any(not k.startswith("2") for k in p["statuses"]):
                problems.append(f"{s}/página {name}: HTTP {p['statuses']}")
        print("| " + " | ".join(row) + " |")
    print()

    explain = (data["ampliado"]["q"] or {}).get("explain", {})
    if explain:
        print("## EXPLAIN (ANALYZE, BUFFERS) no cenário ampliado — consultas mais lentas\n")
        flat = []
        for endpoint, queries in explain.items():
            items = queries.items() if isinstance(queries, dict) else enumerate(queries)
            for index, q in items:
                flat.append((q["execution_ms"], endpoint, index, q))
        for ms, endpoint, index, q in sorted(flat, key=lambda item: -item[0])[:6]:
            seq = [n for n in q["nodes"] if n.startswith("Seq Scan")]
            print(f"- **{endpoint}#{index}**: {ms:.2f} ms (planejamento {q['planning_ms']:.2f} ms, "
                  f"{q['shared_hit']} blocos em cache, {q['shared_read']} lidos); "
                  f"varredura sequencial: {', '.join(seq) or 'nenhuma'}")
            print(f"  `{q['sql'][:220]}{'…' if len(q['sql']) > 220 else ''}`")
        print()

    print("## Verificações\n")
    if problems:
        for problem in problems:
            print(f"- ⚠ {problem}")
    else:
        print("- Todas as respostas 2xx e consultas iguais nos dois cenários.")


if __name__ == "__main__":
    main()
