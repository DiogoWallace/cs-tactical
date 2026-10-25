// Sondagem do envio de demos pelo proxy do Next (etapa M3). Ver docs/training/mvp-m3-envio-de-demos.md.
//
// Roda DENTRO do container `web` da stack E2E (scripts/matches-upload-probe.sh), contra
// http://localhost:3000: mesma origem do navegador, proxy /api do Next, CSRF ativo (APP_ENV=e2e).
// Usa só a conta matches-probe@e2e.test (já com Steam vinculada pelo e2e:prepare) e um arquivo
// sintético (assinatura PBDEMS2 + bytes aleatórios). A senha chega por PROBE_PASSWORD.
// Saída: uma linha por verificação, o id e o SHA-256 do envio concluído (para o .sh conferir no
// disco da API) e código 1 se alguma falhar.

import { createHash, randomBytes } from "node:crypto"

const BASE = "http://localhost:3000"
const PASSWORD = process.env.PROBE_PASSWORD
const PART = 8 * 1024 * 1024
const SIZE = 2 * PART + 123_456

if (!PASSWORD) {
  console.error("PROBE_PASSWORD ausente")
  process.exit(2)
}

const results = []
function check(id, description, ok, detail = "") {
  results.push(ok)
  console.log(`${ok ? "ok  " : "FAIL"} ${id} ${description}${detail ? ` (${detail})` : ""}`)
}

const cookies = new Map()
function store(response) {
  for (const line of response.headers.getSetCookie()) {
    const [pair] = line.split(";")
    const index = pair.indexOf("=")
    cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim())
  }
}

async function request(method, path, { json, bytes, headers = {}, csrf = true } = {}) {
  const xsrf = cookies.get("XSRF-TOKEN")
  const response = await fetch(`${BASE}${path}`, {
    method,
    redirect: "manual",
    headers: {
      Accept: "application/json",
      Origin: BASE,
      Referer: `${BASE}/`,
      ...(json !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(bytes !== undefined ? { "Content-Type": "application/octet-stream" } : {}),
      ...(cookies.size ? { Cookie: [...cookies].map(([k, v]) => `${k}=${v}`).join("; ") } : {}),
      ...(csrf && xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
      ...headers,
    },
    body: json !== undefined ? JSON.stringify(json) : bytes,
  })
  store(response)
  const text = await response.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = null
  }
  return { status: response.status, data }
}

function putPart(id, number, part, extra = {}) {
  return request("PUT", `/api/v1/matches/uploads/${id}/parts/${number}`, {
    bytes: part,
    headers: { "X-Content-SHA256": createHash("sha256").update(part).digest("hex"), ...extra.headers },
    csrf: extra.csrf ?? true,
  })
}

await request("GET", "/sanctum/csrf-cookie", { csrf: false })
const login = await request("POST", "/api/v1/auth/login", {
  json: { email: "matches-probe@e2e.test", password: PASSWORD },
})
if (login.status !== 200 && login.status !== 204) throw new Error(`login respondeu ${login.status}`)

// --- Envio completo, partes de 8 MiB pelo proxy ------------------------------------------------
const file = Buffer.concat([Buffer.from("PBDEMS2\0", "binary"), randomBytes(SIZE - 8)])
const start = await request("POST", "/api/v1/matches/uploads", { json: { name: "sonda.dem", size: SIZE } })
const upload = start.data?.upload
check("M1", "início devolve partes de 8 MiB", start.status === 201 && upload?.part_size === PART && upload?.parts_total === 3, `${start.status}`)

for (let number = 1; number <= 3; number++) {
  const part = file.subarray((number - 1) * PART, number * PART)
  const started = performance.now()
  const response = await putPart(upload.id, number, part)
  const ms = Math.round(performance.now() - started)
  check(`M2.${number}`, `parte ${number} (${part.length} bytes) pelo proxy`, response.status === 200 && response.data?.upload?.parts_received === number, `${response.status}, ${ms} ms`)
}

const complete = await request("POST", `/api/v1/matches/uploads/${upload.id}/complete`)
check("M3", "conclusão", complete.status === 200 && complete.data?.upload?.status === "completed", `${complete.status}`)
const shown = await request("GET", `/api/v1/matches/uploads/${upload.id}`)
check("M4", "consulta mostra concluído, sem caminho nem hash", shown.status === 200 && shown.data?.upload?.status === "completed" && !("sha256" in (shown.data?.upload ?? {})), `${shown.status}`)

// --- Recusas pelo proxy --------------------------------------------------------------------------
const second = await request("POST", "/api/v1/matches/uploads", { json: { name: "outra.dem", size: SIZE } })
const other = second.data?.upload
const oversized = await putPart(other.id, 1, randomBytes(PART + 1))
check("M5", "parte maior que o tamanho fixo → 422", oversized.status === 422 && oversized.data?.code === "match_upload_part_size_invalid", `${oversized.status} ${oversized.data?.code ?? ""}`)
const noCsrf = await putPart(other.id, 1, file.subarray(0, PART), { csrf: false })
check("M6", "parte sem token CSRF → 419", noCsrf.status === 419, `${noCsrf.status}`)
const cancel = await request("DELETE", `/api/v1/matches/uploads/${other.id}`)
check("M7", "cancelamento → 204", cancel.status === 204, `${cancel.status}`)

console.log(`UPLOAD ${upload.id} ${createHash("sha256").update(file).digest("hex")}`)
console.log(`\n${results.filter(Boolean).length}/${results.length} verificações aprovadas`)
process.exit(results.every(Boolean) ? 0 : 1)
