// Sondagem de segurança da Academy e do Learning (etapa 2.7b, itens S2, S3, S4 e S8).
//
// Roda DENTRO do container `web` da stack E2E (scripts/security-probe.sh), contra
// http://localhost:3000 daquela stack: mesma origem do navegador, proxy /api do Next, Sanctum com
// CSRF ativo (APP_ENV=e2e). Nunca contra desenvolvimento ou produção.
//
// Usa só as contas probe-*@e2e.test (PrepareE2eDatabaseCommand) e o curso de fixture
// fundamentos-e2e. A senha chega por PROBE_PASSWORD; nada é impresso além do resultado.
// Saída: uma linha por verificação e código 1 se alguma falhar.

const BASE = "http://localhost:3000"
const ORIGIN = BASE
const PASSWORD = process.env.PROBE_PASSWORD
const COURSE = "fundamentos-e2e"
const LESSON = "counter-strafe"
const QUIZ = "counter-strafe-e2e"
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

if (!PASSWORD) {
  console.error("PROBE_PASSWORD ausente")
  process.exit(2)
}

const results = []
const statuses = []

function check(id, description, ok, detail = "") {
  results.push({ id, ok })
  console.log(`${ok ? "ok  " : "FAIL"} ${id} ${description}${detail ? ` (${detail})` : ""}`)
}

/** Cliente com cookies próprios, como um navegador. */
class Client {
  constructor(name) {
    this.name = name
    this.cookies = new Map()
  }

  cookieHeader() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ")
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

  xsrf() {
    const raw = this.cookies.get("XSRF-TOKEN")
    return raw ? decodeURIComponent(raw) : ""
  }

  async request(method, path, { body, csrf = true, headers = {} } = {}) {
    const response = await fetch(`${BASE}${path}`, {
      method,
      redirect: "manual",
      headers: {
        Accept: "application/json",
        Origin: ORIGIN,
        Referer: `${ORIGIN}/`,
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(this.cookies.size ? { Cookie: this.cookieHeader() } : {}),
        ...(csrf && this.xsrf() ? { "X-XSRF-TOKEN": this.xsrf() } : {}),
        ...headers,
      },
      body: body === undefined ? undefined : typeof body === "string" ? body : JSON.stringify(body),
    })
    this.store(response)
    const text = await response.text()
    let json = null
    try {
      json = text ? JSON.parse(text) : null
    } catch {
      json = null
    }
    statuses.push({ method, path, status: response.status })
    return { status: response.status, json, text, headers: response.headers }
  }

  async login(email) {
    await this.request("GET", "/sanctum/csrf-cookie", { csrf: false })
    const response = await this.request("POST", "/api/v1/auth/login", {
      body: { email, password: PASSWORD },
    })
    if (response.status !== 200 && response.status !== 204) {
      throw new Error(`login de ${email} respondeu ${response.status}`)
    }
  }
}

function answersFor(attempt, right) {
  // Sem gabarito no cliente: a "certa" é escolhida pelo texto da opção da fixture.
  return attempt.questions.map((question) => {
    const pick = question.options.filter((option) =>
      right
        ? ["OPCAO-CERTA-E2E", "No peek"].includes(option.text)
        : ["OPCAO-ERRADA-E2E", "Na rotação"].includes(option.text),
    )
    return { question_id: question.id, option_ids: pick.map((option) => option.id) }
  })
}

const a = new Client("A")
const b = new Client("B")
const c = new Client("C")
await a.login("probe-a@e2e.test")
await b.login("probe-b@e2e.test")
await c.login("probe-c@e2e.test")

// ---------------------------------------------------------------- S3: sessão e CSRF
const anon = new Client("anon")
for (const path of ["/api/v1/me", "/api/v1/me/enrollments", `/api/v1/academy/courses/${COURSE}/progress`, `/api/v1/me/quiz-attempts?quiz=${QUIZ}`]) {
  const response = await anon.request("GET", path)
  check("S3.1", `sem sessão ${path} → 401`, response.status === 401, String(response.status))
}

const writes = [
  ["PUT", `/api/v1/academy/courses/${COURSE}/enrollment`],
  ["PUT", `/api/v1/academy/courses/${COURSE}/lessons/${LESSON}/completion`],
  ["POST", `/api/v1/academy/quizzes/${QUIZ}/attempts`],
]
for (const [method, path] of writes) {
  const response = await c.request(method, path, { csrf: false })
  check("S3.2", `escrita sem token CSRF ${method} ${path} → 419`, response.status === 419, String(response.status))
}
for (const [method, path] of writes) {
  const response = await c.request(method, path, { csrf: false, headers: { "X-XSRF-TOKEN": b.xsrf() } })
  check("S3.3", `escrita com token CSRF de outra sessão ${method} ${path} → 419`, response.status === 419, String(response.status))
}

const tampered = new Client("tampered")
for (const [name, value] of a.cookies) tampered.cookies.set(name, name.endsWith("session") ? `${value.slice(0, -4)}AAAA` : value)
const tamperedMe = await tampered.request("GET", "/api/v1/me")
check("S3.4", "cookie de sessão adulterado → 401", tamperedMe.status === 401, String(tamperedMe.status))

// ---------------------------------------------------------------- S2: IDOR e gabarito
const enroll = await a.request("PUT", `/api/v1/academy/courses/${COURSE}/enrollment`)
check("S2.0", "A se matricula (201)", enroll.status === 201, String(enroll.status))
const start = await a.request("POST", `/api/v1/academy/quizzes/${QUIZ}/attempts`)
const attempt = start.json?.data
check("S2.1", "A inicia tentativa (201)", start.status === 201 && UUID.test(attempt?.id ?? ""), String(start.status))
check(
  "S2.2",
  "tentativa aberta sem gabarito nem explicação",
  !/"correct"|"explanation"/.test(start.text) && !start.text.includes("EXPLICACAO-SECRETA-E2E"),
)

const idor = [
  ["GET", `/api/v1/academy/quiz-attempts/${attempt.id}`, undefined],
  ["POST", `/api/v1/academy/quiz-attempts/${attempt.id}/submission`, { answers: answersFor(attempt, true) }],
]
for (const [method, path, body] of idor) {
  const response = await b.request(method, path, { body })
  check("S2.3", `B ${method} na tentativa de A → 404`, response.status === 404, String(response.status))
}
const bList = await b.request("GET", `/api/v1/me/quiz-attempts?quiz=${QUIZ}`)
check("S2.4", "lista de B não traz a tentativa de A", bList.status === 200 && !bList.text.includes(attempt.id), String(bList.status))
const bEnroll = await b.request("GET", "/api/v1/me/enrollments")
check("S2.5", "matrículas de B vazias", bEnroll.status === 200 && (bEnroll.json?.data ?? []).length === 0, String(bEnroll.status))
for (const id of ["00000000-0000-7000-8000-000000000000", "nao-e-uuid", "..%2F..%2Fme"]) {
  const response = await a.request("GET", `/api/v1/academy/quiz-attempts/${id}`)
  check("S2.6", `tentativa inexistente ou caminho manipulado (${id}) → 404`, response.status === 404, String(response.status))
}
for (const path of [`/api/v1/academy/courses/${COURSE}/lessons/execucao-a/completion`, "/api/v1/academy/courses/..%2Fme/enrollment"]) {
  const response = await a.request("PUT", path)
  check("S2.7", `conclusão/matrícula em caminho de outro curso ou manipulado → 404`, response.status === 404, `${path} ${response.status}`)
}

// ---------------------------------------------------------------- S8: integridade
const wrong = await a.request("POST", `/api/v1/academy/quiz-attempts/${attempt.id}/submission`, {
  body: { answers: answersFor(attempt, false), score_percent: 100, passed: true },
})
check(
  "S8.1",
  "envio reprovado; nota e aprovação do cliente ignoradas; sem gabarito antes da liberação",
  wrong.status === 200 && wrong.json?.data?.passed === false && !wrong.text.includes("EXPLICACAO-SECRETA-E2E"),
  String(wrong.status),
)
const again = await a.request("POST", `/api/v1/academy/quiz-attempts/${attempt.id}/submission`, {
  body: { answers: answersFor(attempt, true) },
})
check("S8.2", "segundo envio da mesma tentativa → 409", again.status === 409 && again.json?.code === "quiz_attempt_already_submitted", `${again.status} ${again.json?.code}`)

const cStart = await c.request("POST", `/api/v1/academy/quizzes/${QUIZ}/attempts`)
const cAttempt = cStart.json?.data
const q = cAttempt.questions
const malformed = [
  ["answers não é lista", { answers: "x" }],
  ["sem respostas", { answers: [] }],
  ["pergunta repetida", { answers: [{ question_id: q[0].id, option_ids: [q[0].options[0].id] }, { question_id: q[0].id, option_ids: [q[0].options[0].id] }] }],
  ["opção de outra pergunta", { answers: [{ question_id: q[0].id, option_ids: [q[1].options[0].id] }, { question_id: q[1].id, option_ids: [q[1].options[0].id] }] }],
  ["ids que não são UUID", { answers: [{ question_id: "x", option_ids: ["y"] }, { question_id: 1, option_ids: [null] }] }],
  ["mil respostas", { answers: Array.from({ length: 1000 }, () => ({ question_id: q[0].id, option_ids: [q[0].options[0].id] })) }],
]
for (const [label, body] of malformed) {
  const response = await c.request("POST", `/api/v1/academy/quiz-attempts/${cAttempt.id}/submission`, { body })
  check("S8.3", `envio malformado (${label}) → 422`, response.status === 422, String(response.status))
}
const notJson = await c.request("POST", `/api/v1/academy/quiz-attempts/${cAttempt.id}/submission`, { body: "{nao json" })
check("S8.4", "corpo que não é JSON → 4xx, nunca 500", notJson.status >= 400 && notJson.status < 500, String(notJson.status))
const stillOpen = await c.request("GET", `/api/v1/academy/quiz-attempts/${cAttempt.id}`)
check("S8.5", "tentativa continua aberta depois dos envios recusados", stillOpen.json?.data?.status === "in_progress", stillOpen.json?.data?.status)

// Esgotar (max_attempts = 2 na fixture): a segunda tentativa reprovada libera a revisão.
const second = await a.request("POST", `/api/v1/academy/quizzes/${QUIZ}/attempts`)
await a.request("POST", `/api/v1/academy/quiz-attempts/${second.json.data.id}/submission`, {
  body: { answers: answersFor(second.json.data, false) },
})
const third = await a.request("POST", `/api/v1/academy/quizzes/${QUIZ}/attempts`)
check("S8.6", "terceira tentativa depois de esgotar → 409", third.status === 409 && third.json?.code === "quiz_attempts_exhausted", `${third.status} ${third.json?.code}`)
const review = await a.request("GET", `/api/v1/academy/quiz-attempts/${attempt.id}`)
check("S8.7", "revisão liberada depois de esgotar (R20)", review.text.includes("EXPLICACAO-SECRETA-E2E"))

// ---------------------------------------------------------------- S4: cache das respostas
const headersOf = async (client, path) => (await client.request("GET", path)).headers.get("cache-control") ?? "(ausente)"
for (const path of ["/api/v1/me", "/api/v1/me/enrollments", `/api/v1/academy/courses/${COURSE}/progress`, `/api/v1/academy/quiz-attempts/${attempt.id}`]) {
  const value = await headersOf(a, path)
  // Registro: o critério do plano pede no-store; private já impede caches compartilhados.
  check("S4.1", `Cache-Control de ${path.replace(attempt.id, "{attempt}")}: ${value}`, /private|no-store/.test(value))
}

// ---------------------------------------------------------------- S3: logout
// O cookie antigo é copiado antes do logout (a resposta do logout troca os cookies de A).
const old = new Client("old")
for (const [name, value] of a.cookies) old.cookies.set(name, value)
const logout = await a.request("POST", "/api/v1/auth/logout")
const afterLogout = await old.request("GET", "/api/v1/me")
check("S3.5", "logout e cookie antigo → 401", [200, 204].includes(logout.status) && afterLogout.status === 401, `${logout.status}/${afterLogout.status}`)

// ---------------------------------------------------------------- nenhum 500
const errors = statuses.filter((entry) => entry.status >= 500)
check("S8.8", `nenhuma resposta 5xx em ${statuses.length} requisições`, errors.length === 0, errors.map((e) => `${e.method} ${e.path} ${e.status}`).join("; "))

const failed = results.filter((result) => !result.ok).length
console.log(`\n${results.length - failed}/${results.length} verificações aprovadas`)
process.exit(failed === 0 ? 0 : 1)
