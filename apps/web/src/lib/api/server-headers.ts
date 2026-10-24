/**
 * Headers das chamadas à API feitas no servidor (Server Components e `proxy.ts`).
 *
 * - Repassa os cookies da requisição do usuário, para que o Laravel reconheça a sessão.
 * - Envia Origin/Referer do frontend, para o Sanctum tratar a chamada como "stateful".
 * - Repassa o X-Forwarded-For recebido, como faz o proxy de /api (ADR 0009).
 */
export function buildServerHeaders(
  cookieHeader: string,
  appUrl: string,
  forwardedFor?: string | null,
): Headers {
  const headers = new Headers({
    Accept: "application/json",
    Origin: new URL(appUrl).origin,
    Referer: `${new URL(appUrl).origin}/`,
  })
  if (cookieHeader) headers.set("Cookie", cookieHeader)
  if (forwardedFor) headers.set("X-Forwarded-For", forwardedFor)
  return headers
}
