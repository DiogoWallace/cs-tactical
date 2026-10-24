// Tempos por HTTP da etapa 2.7d: API (Nginx + PHP-FPM) e páginas do Next (build de produção).
// Ver docs/development/desempenho.md.
//
// Roda DENTRO do container `web` da stack E2E (scripts/perf.sh), contra http://api:8080 (API
// direto, sem o Next) e http://localhost:3000 (páginas). Nunca contra desenvolvimento ou produção.
// Usa as contas perf-1..perf-5@e2e.test (scripts/perf/seed-players.php); a senha chega por
// PERF_PASSWORD. Os limites de requisição não mudam: o script espaça as chamadas para ficar abaixo
// deles (academy-public 120/min por IP, api 120/min por usuário, learning-write 30/min,
// quiz-attempt 10/min por usuário).
//
// Saída: um JSON na saída padrão (amostras resumidas e janelas de tempo das páginas, que o
// scripts/perf.sh cruza com o log de acesso da API para contar as chamadas por página).

const API = "http://api:8080"
const WEB = "http://localhost:3000"
const ORIGIN = WEB
const PASSWORD = process.env.PERF_PASSWORD
const SCENARIO = process.env.PERF_SCENARIO
const SAMPLES = Number(process.env.PERF_SAMPLES ?? 20)
const WARMUP = 3
const GAP_MS = 700 // ≤ 86 requisições/min em qualquer limite
const ATTEMPT_GAP_MS = 3000 // quiz-attempt: 10/min por usuário, com 4 usuários em rodízio
const PAGE_GAP_MS = 1500 // páginas logadas chamam a API 1–3 vezes com a chave do perf-1

if (!PASSWORD || !["pequeno", "ampliado"].includes(SCENARIO)) {
  console.error("PERF_PASSWORD e PERF_SCENARIO (pequeno|ampliado) são obrigatórios")
  process.exit(2)
}

const TARGET =
  SCENARIO === "pequeno"
    ? { course: "fundamentos-e2e", lesson: "counter-strafe", quiz: "counter-strafe-e2e" }
    : { course: "perf-curso-10", lesson: "m3-aula-1", quiz: "perf-curso-10-m1-quiz" }
// Quizzes ainda sem tentativas para a medição de escrita (um por tentativa no ampliado).
const ATTEMPT_QUIZZES =
  SCENARIO === "pequeno"
    ? Array(8).fill("counter-strafe-e2e")
    : Array.from({ length: 8 }, (_, i) => `perf-curso-${41 + Math.floor(i / 5)}-m${(i % 5) + 1}-quiz`)

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** Cliente com cookies próprios, como um navegador (mesmo padrão do security-probe.mjs). */
class Client {
  constructor(base) {
    this.base = base
    this.cookies = new Map()
  }

  store(response) {
    for (const line of response.headers.getSetCookie()) {
      const [pair] = line.split(";")
      const index = pair.indexOf("=")
      const name = pair.slice(0, index).trim()
      const value = pair.slice(index + 1).trim()
      if (/max-age=0|expires=thu, 01 jan 1970/i.test(line) || value === "") this.cookies.delete(name)
      else this.cookies.set(name, value)
    }
  }

  headers(json = true) {
    const xsrf = this.cookies.get("XSRF-TOKEN")
    return {
      ...(json ? { Accept: "application/json", "Content-Type": "application/json" } : {}),
      Origin: ORIGIN,
      Referer: `${ORIGIN}/`,
      ...(this.cookies.size ? { Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ") } : {}),
      ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
    }
  }

  /** Tempo até os headers (TTFB) e até o fim do corpo (inclui o streaming do Next). */
  async timed(method, path, { body, json = true } = {}) {
    const started = performance.now()
    const response = await fetch(`${this.base}${path}`, {
      method,
      redirect: "manual",
      headers: this.headers(json),
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    const ttfb = performance.now() - started
    this.store(response)
    const text = await response.text()
    const total = performance.now() - started
    let data = null
    try {
      data = json && text ? JSON.parse(text) : null
    } catch {
      data = null
    }
    return { status: response.status, ttfb, total, data }
  }

  async login(email) {
    await this.timed("GET", "/sanctum/csrf-cookie")
    const response = await this.timed("POST", "/api/v1/auth/login", { body: { email, password: PASSWORD } })
    if (response.status !== 200 && response.status !== 204) throw new Error(`login de ${email}: ${response.status}`)
  }
}

function summarize(samples) {
  const sorted = (key) => samples.map((s) => s[key]).sort((a, b) => a - b)
  const pick = (values, p) => values[Math.min(values.length - 1, Math.ceil((p / 100) * values.length) - 1)]
  const out = { n: samples.length, statuses: {} }
  for (const s of samples) out.statuses[s.status] = (out.statuses[s.status] ?? 0) + 1
  for (const key of ["ttfb", "total"]) {
    const values = sorted(key)
    out[key] = {
      p50: +pick(values, 50).toFixed(1),
      p95: +pick(values, 95).toFixed(1),
      min: +values[0].toFixed(1),
      max: +values.at(-1).toFixed(1),
    }
  }
  return out
}

async function measure(client, method, path, { body, json = true, gap = GAP_MS, samples = SAMPLES } = {}) {
  const results = []
  for (let i = 0; i < WARMUP + samples; i++) {
    const result = await client.timed(method, path, { body, json })
    if (i >= WARMUP) results.push(result)
    await sleep(gap)
  }
  return summarize(results)
}

const result = { scenario: SCENARIO, target: TARGET, samples: SAMPLES, api: {}, pages: {}, windows: [] }

// --- API: leituras --------------------------------------------------------------------------
const visitor = new Client(API)
await visitor.timed("GET", "/sanctum/csrf-cookie")
const player = new Client(API)
await player.login("perf-1@e2e.test")

const attempts = (await player.timed("GET", `/api/v1/me/quiz-attempts?quiz=${TARGET.quiz}`)).data?.data ?? []
const attemptId = attempts[0]?.id

const reads = {
  catalogo: [visitor, "/api/v1/academy/courses"],
  curso: [visitor, `/api/v1/academy/courses/${TARGET.course}`],
  aula: [visitor, `/api/v1/academy/courses/${TARGET.course}/lessons/${TARGET.lesson}`],
  progresso: [player, `/api/v1/academy/courses/${TARGET.course}/progress`],
  matriculas: [player, "/api/v1/me/enrollments"],
  tentativas_do_quiz: [player, `/api/v1/me/quiz-attempts?quiz=${TARGET.quiz}`],
  tentativa: [player, `/api/v1/academy/quiz-attempts/${attemptId}`],
}
for (const [name, [client, path]] of Object.entries(reads)) {
  result.api[name] = await measure(client, "GET", path)
}

// --- API: escritas --------------------------------------------------------------------------
// Matrícula e conclusão repetidas (caminho idempotente, comparável entre cenários); o primeiro
// acesso de cada escrita tem a contagem de consultas medida no scripts/perf/api-queries.php.
const writer = new Client(API)
await writer.login("perf-2@e2e.test")
const writeSamples = Math.min(SAMPLES, 10)
result.api.matricula = await measure(writer, "PUT", `/api/v1/academy/courses/${TARGET.course}/enrollment`, { samples: writeSamples })
result.api.conclusao = await measure(writer, "PUT", `/api/v1/academy/courses/${TARGET.course}/lessons/${TARGET.lesson}/completion`, { samples: writeSamples })

// Início e envio de tentativa: 8 tentativas novas, perf-2..perf-5 em rodízio.
const quizClients = [writer]
for (const n of [3, 4, 5]) {
  const client = new Client(API)
  await client.login(`perf-${n}@e2e.test`)
  quizClients.push(client)
}
const starts = []
const submits = []
for (const [i, quiz] of ATTEMPT_QUIZZES.entries()) {
  const client = quizClients[i % quizClients.length]
  const start = await client.timed("POST", `/api/v1/academy/quizzes/${quiz}/attempts`)
  starts.push(start)
  await sleep(ATTEMPT_GAP_MS)
  const attempt = start.data?.data
  if (attempt?.id) {
    const answers = attempt.questions.map((q) => ({ question_id: q.id, option_ids: [q.options[0].id] }))
    submits.push(await client.timed("POST", `/api/v1/academy/quiz-attempts/${attempt.id}/submission`, { body: { answers } }))
  }
  await sleep(ATTEMPT_GAP_MS)
}
result.api.inicio_tentativa = summarize(starts)
result.api.envio_tentativa = submits.length ? summarize(submits) : { n: 0 }

// --- Páginas do Next ------------------------------------------------------------------------
// O limite `api` do perf-1 (120/min) foi usado pelas leituras: espera a janela virar.
await sleep(61_000)
const guest = new Client(WEB)
const member = new Client(WEB)
await member.login("perf-1@e2e.test")

const pagePaths = {
  academy: "/academy",
  curso: `/academy/cursos/${TARGET.course}`,
  aula: `/academy/cursos/${TARGET.course}/aulas/${TARGET.lesson}`,
}
const pages = [
  ...Object.entries(pagePaths).map(([name, path]) => [`visitante_${name}`, guest, path]),
  ...Object.entries(pagePaths).map(([name, path]) => [`logado_${name}`, member, path]),
  ["logado_painel", member, "/painel"],
  ["logado_meu_progresso", member, "/painel/academy"],
]
const pageSamples = Math.min(SAMPLES, 10)
for (const [name, client, path] of pages) {
  result.pages[name] = await measure(client, "GET", path, { json: false, gap: PAGE_GAP_MS, samples: pageSamples })
}

// Janelas isoladas, uma página por vez, para contar as chamadas à API pelo log de acesso. O log
// do Nginx tem resolução de segundo: cada janela começa num segundo novo e fica a 3 s da próxima.
await sleep(2_000)
for (const [name, client, path] of pages) {
  await sleep(1_000 - (Date.now() % 1_000))
  const from = new Date().toISOString()
  const page = await client.timed("GET", path, { json: false })
  await sleep(400)
  result.windows.push({ name, path, status: page.status, from, to: new Date().toISOString() })
  await sleep(3_000)
}

console.log(JSON.stringify(result, null, 2))
