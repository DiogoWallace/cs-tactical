import { expect, test } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login, submitLogin } from "../support/auth"

/** Valores de `next` que levariam a outro site (open redirect) ou executariam código. */
const UNSAFE_NEXT = [
  "//evil.example",
  "https://evil.example",
  "/\\evil.example",
  "javascript:alert(1)",
  "/\t/evil.example",
  "/\n/evil.example",
]

test.describe("redirecionamentos seguros", () => {
  test("login com next externo termina no painel, na mesma origem", async ({ page, baseURL }) => {
    await submitLogin(page, ACCOUNTS.redirect, { next: "//evil.example/roubar" })

    await expect(page).toHaveURL("/painel")
    expect(new URL(page.url()).origin).toBe(new URL(baseURL!).origin)
  })

  test("com sessão, nenhum next inseguro sai da aplicação", async ({ page, baseURL }) => {
    await login(page, ACCOUNTS.redirect)

    for (const next of UNSAFE_NEXT) {
      await test.step(JSON.stringify(next), async () => {
        await page.goto(`/login?${new URLSearchParams({ next })}`)
        await expect(page).toHaveURL("/painel")
        expect(new URL(page.url()).origin).toBe(new URL(baseURL!).origin)
      })
    }
  })

  test("com sessão, um next interno é respeitado com a query", async ({ page }) => {
    await login(page, ACCOUNTS.redirect)

    await page.goto(`/login?${new URLSearchParams({ next: "/painel/conta?aba=email" })}`)

    await expect(page).toHaveURL("/painel/conta?aba=email")
  })
})
