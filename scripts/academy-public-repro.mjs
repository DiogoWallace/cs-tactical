// Reprodução controlada do limite `academy-public` compartilhado na stack E2E (etapa 2.7b, S9, R1).
//
// Roda DENTRO do container `web` da stack E2E (scripts/academy-public-repro.sh). Não muda nada:
// só lê o catálogo, como visitante, até o limite de 120 leituras por minuto aparecer.
//
// Dois clientes distintos, como dois testes do Playwright:
//   1. "páginas": abre /academy (o Server Component lê a API pelo serverFetch);
//   2. "navegador": chama /api/v1/academy/courses pelo proxy (rewrite) do Next.
// Se o primeiro esgota o limite e o segundo recebe 429 sem ter feito quase nada, os dois
// compartilham a mesma chave: o IP do container web.

const BASE = "http://localhost:3000"
const UNAVAILABLE = "A Academy está temporariamente indisponível"

const started = Date.now()
const pages = { ok: 0, unavailable: 0, other: 0, firstUnavailableAt: null }

for (let i = 1; i <= 130; i++) {
  const response = await fetch(`${BASE}/academy`)
  const html = await response.text()
  if (html.includes(UNAVAILABLE)) {
    pages.unavailable++
    pages.firstUnavailableAt ??= i
  } else if (response.ok) {
    pages.ok++
  } else {
    pages.other++
  }
}

const browser = await fetch(`${BASE}/api/v1/academy/courses`, { headers: { Accept: "application/json" } })

console.log(
  JSON.stringify(
    {
      seconds: Math.round((Date.now() - started) / 1000),
      pages,
      browserAfterPages: { status: browser.status, retryAfter: browser.headers.get("retry-after") },
    },
    null,
    2,
  ),
)
