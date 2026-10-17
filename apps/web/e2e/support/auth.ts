import { expect, type BrowserContext, type Locator, type Page } from "@playwright/test"

import { E2E_PASSWORD, type Account } from "./accounts"

export const SESSION_COOKIE = "cs_tactical_session"

/**
 * Campo pelo rótulo exato, só entre os visíveis. O Next 16 (Cache Components) mantém páginas
 * já visitadas escondidas no DOM (`<Activity>`), então o mesmo formulário pode existir duas vezes.
 */
export function field(scope: Page | Locator, label: string): Locator {
  return scope.getByLabel(label, { exact: true }).filter({ visible: true })
}

type LoginOptions = { password?: string; remember?: boolean; next?: string }

/** Preenche e envia o login. Não espera o destino: cada teste confere para onde foi. */
export async function submitLogin(page: Page, account: Account, options: LoginOptions = {}) {
  const query = options.next ? `?${new URLSearchParams({ next: options.next })}` : ""
  await page.goto(`/login${query}`)
  await field(page, "E-mail").fill(account.email)
  await field(page, "Senha").fill(options.password ?? E2E_PASSWORD)
  if (options.remember) {
    await field(page, "Manter conectado por 30 dias").check()
  }
  await page.getByRole("button", { name: "Entrar" }).click()
}

/** Login completo, terminando no painel. */
export async function login(page: Page, account: Account, options: LoginOptions = {}) {
  await submitLogin(page, account, options)
  await expect(page).toHaveURL("/painel")
  await expect(
    page.getByRole("heading", { level: 1, name: `Olá, ${account.nickname}` }),
  ).toBeVisible()
}

export async function sessionCookie(context: BrowserContext) {
  return (await context.cookies()).find((cookie) => cookie.name === SESSION_COOKIE)
}
