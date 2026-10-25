// Provedor OpenID 2.0 falso, só para a stack E2E (etapa M2, decisão S1). docs/development/e2e.md
//
// Faz o papel do login da Steam sem internet: mostra uma página onde o teste escolhe o SteamID64 e
// autoriza ou cancela, volta para a página do frontend com uma resposta assinada (HMAC) e responde
// ao `check_authentication` da API. A API só usa este endereço porque STEAM_OPENID_ENDPOINT é
// lido fora de produção; em produção o endpoint é sempre o oficial.
//
// Sem dependências. Só redireciona para FRONTEND_ORIGIN (nunca um destino arbitrário) e recusa
// assinatura alterada e nonce repetido, como a Steam.

import { createHmac, randomBytes, timingSafeEqual } from "node:crypto"
import { createServer } from "node:http"

const PORT = Number(process.env.PORT ?? 8090)
const ENDPOINT = process.env.STEAM_OPENID_ENDPOINT ?? "http://steam-openid:8090/openid/login"
const FRONTEND_ORIGIN = process.env.FRONTEND_ORIGIN ?? "http://localhost:3000"
const NS = "http://specs.openid.net/auth/2.0"
const SIGNED = ["op_endpoint", "claimed_id", "identity", "return_to", "response_nonce", "assoc_handle"]
const SECRET = randomBytes(32)
const usedNonces = new Set()

const escape = (value) =>
  String(value).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c])

function sign(fields) {
  const data = ["signed", ...SIGNED].map((key) => `${key}:${fields[key]}\n`).join("")
  return createHmac("sha256", SECRET).update(data).digest("base64")
}

function sameSignature(a, b) {
  const left = Buffer.from(a)
  const right = Buffer.from(b)
  return left.length === right.length && timingSafeEqual(left, right)
}

function returnUrl(returnTo, fields) {
  const url = new URL(returnTo)
  for (const [key, value] of Object.entries(fields)) url.searchParams.append(`openid.${key}`, value)
  return url.toString()
}

function allowedReturnTo(value) {
  try {
    return new URL(value).origin === FRONTEND_ORIGIN ? value : null
  } catch {
    return null
  }
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, { "Content-Type": "text/plain; charset=utf-8", ...headers })
  res.end(body)
}

function loginPage(returnTo) {
  return `<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Steam (simulada para testes)</title></head>
<body>
<main>
<h1>Steam (simulada para testes)</h1>
<form method="get" action="/openid/approve">
<input type="hidden" name="return_to" value="${escape(returnTo)}">
<label for="steamid">SteamID64</label>
<input id="steamid" name="steamid" value="76561198000000001" inputmode="numeric">
<button type="submit" name="decision" value="allow">Entrar e autorizar</button>
<button type="submit" name="decision" value="cancel">Cancelar</button>
</form>
</main>
</body></html>`
}

function approve(res, query) {
  const returnTo = allowedReturnTo(query.get("return_to"))
  if (!returnTo) return send(res, 400, "return_to fora do frontend")

  if (query.get("decision") === "cancel") {
    return send(res, 302, "", { Location: returnUrl(returnTo, { ns: NS, mode: "cancel" }) })
  }

  const steamId = query.get("steamid") ?? ""
  if (!/^7656119\d{10}$/.test(steamId)) return send(res, 400, "SteamID64 inválido")

  const identity = `https://steamcommunity.com/openid/id/${steamId}`
  const fields = {
    ns: NS,
    mode: "id_res",
    op_endpoint: ENDPOINT,
    claimed_id: identity,
    identity,
    return_to: returnTo,
    response_nonce: `${new Date().toISOString().replace(/\.\d+Z$/, "Z")}${randomBytes(6).toString("hex")}`,
    assoc_handle: randomBytes(8).toString("hex"),
    signed: ["signed", ...SIGNED].join(","),
  }
  fields.sig = sign(fields)
  return send(res, 302, "", { Location: returnUrl(returnTo, fields) })
}

function checkAuthentication(res, form) {
  const fields = {}
  for (const [key, value] of form) {
    if (key.startsWith("openid.")) fields[key.slice("openid.".length)] = value
  }
  const valid =
    fields.mode === "check_authentication" &&
    fields.signed === ["signed", ...SIGNED].join(",") &&
    typeof fields.sig === "string" &&
    sameSignature(sign(fields), fields.sig) &&
    !usedNonces.has(fields.response_nonce)
  if (valid) usedNonces.add(fields.response_nonce)
  send(res, 200, `ns:${NS}\nis_valid:${valid ? "true" : "false"}\n`)
}

createServer((req, res) => {
  const url = new URL(req.url ?? "/", "http://steam-openid")

  if (req.method === "GET" && url.pathname === "/health") return send(res, 200, "ok")

  if (req.method === "GET" && url.pathname === "/openid/login") {
    const returnTo = allowedReturnTo(url.searchParams.get("openid.return_to"))
    if (url.searchParams.get("openid.mode") !== "checkid_setup" || !returnTo) {
      return send(res, 400, "pedido OpenID inválido")
    }
    return send(res, 200, loginPage(returnTo), { "Content-Type": "text/html; charset=utf-8" })
  }

  if (req.method === "GET" && url.pathname === "/openid/approve") return approve(res, url.searchParams)

  if (req.method === "POST" && url.pathname === "/openid/login") {
    let body = ""
    req.on("data", (chunk) => {
      body += chunk
      if (body.length > 65536) req.destroy()
    })
    req.on("end", () => checkAuthentication(res, new URLSearchParams(body)))
    return
  }

  send(res, 404, "não encontrado")
}).listen(PORT, "0.0.0.0", () => console.log(`Steam OpenID falso em :${PORT} (endpoint ${ENDPOINT})`))
