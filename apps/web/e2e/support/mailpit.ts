import { expect, type APIRequestContext } from "@playwright/test"

/**
 * Leitura dos e-mails enviados pela API (fila -> worker -> Mailpit da stack E2E).
 * Nada de sleep: `expect.poll` consulta a API do Mailpit até a mensagem chegar.
 */
const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? "http://mailpit:8025"

type Summary = { ID: string; Subject: string; To: { Address: string }[] }
type Message = Summary & { Text: string; HTML: string }

export async function mailpitIsReachable(request: APIRequestContext) {
  const response = await request.get(`${MAILPIT_URL}/api/v1/info`)
  return response.ok()
}

async function search(request: APIRequestContext, to: string): Promise<Summary[]> {
  const query = encodeURIComponent(`to:"${to}"`)
  const response = await request.get(`${MAILPIT_URL}/api/v1/search?query=${query}&limit=50`)
  expect(response.ok(), "busca no Mailpit").toBe(true)
  const body = (await response.json()) as { messages: Summary[] }
  return body.messages
}

/**
 * Caixa de entrada de um endereço. Chame `open()` ANTES da ação que envia o e-mail: `next()`
 * espera uma mensagem que ainda não existia naquele momento, então e-mails antigos (de outra
 * execução ou de outro passo) nunca são confundidos com o novo.
 */
export async function openMailbox(request: APIRequestContext, to: string) {
  const seen = new Set((await search(request, to)).map((message) => message.ID))

  return {
    async next(subject: RegExp): Promise<Message> {
      let found: Summary | undefined
      await expect
        .poll(
          async () => {
            found = (await search(request, to)).find(
              (message) => !seen.has(message.ID) && subject.test(message.Subject),
            )
            return found !== undefined
          },
          { message: `e-mail "${subject}" para ${to}`, timeout: 20_000 },
        )
        .toBe(true)
      seen.add(found!.ID)

      const response = await request.get(`${MAILPIT_URL}/api/v1/message/${found!.ID}`)
      expect(response.ok()).toBe(true)
      return (await response.json()) as Message
    },
  }
}

/** Primeiro link do e-mail que aponta para o caminho informado (ex.: "/verificar-email/"). */
export function linkFrom(message: Message, path: string): string {
  const links = [...message.HTML.matchAll(/href="([^"]+)"/g)].map((match) =>
    (match[1] ?? "").replaceAll("&amp;", "&"),
  )
  const link = links.find((href) => URL.canParse(href) && new URL(href).pathname.startsWith(path))
  expect(link, `link para ${path} no e-mail "${message.Subject}"`).toBeDefined()
  return link!
}
