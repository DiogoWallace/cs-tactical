import { expect, test } from "@playwright/test"

import { ACCOUNTS, E2E_PASSWORD } from "../support/accounts"
import { field, login } from "../support/auth"
import { linkFrom, openMailbox } from "../support/mailpit"

const account = ACCOUNTS.verify

// Passos dependentes (a conta termina verificada): rodam em ordem, no mesmo worker.
test.describe.configure({ mode: "serial" })

test.describe("verificação de e-mail", () => {
  let verificationLink: string

  test("conta não verificada vê o aviso e reenvia o link", async ({ page, request }) => {
    await login(page, account)
    await expect(page.getByText("Não verificado", { exact: true })).toBeVisible()
    await expect(page.getByText("Confirme seu e-mail")).toBeVisible()

    const mailbox = await openMailbox(request, account.email)
    await page.getByRole("button", { name: "Reenviar e-mail de confirmação" }).click()
    await expect(page.getByText(`Enviamos um novo link para ${account.email}`)).toBeVisible()

    const message = await mailbox.next(/Confirme seu e-mail/)
    verificationLink = linkFrom(message, "/verificar-email/")
    expect(new URL(verificationLink).origin).toBe("http://localhost:3000")
  })

  test("link adulterado é recusado", async ({ page }) => {
    await login(page, account)
    const tampered = new URL(verificationLink)
    tampered.pathname = tampered.pathname.replace(/[0-9a-f]{40}$/, "0".repeat(40))

    await page.goto(tampered.pathname + tampered.search)

    await expect(
      page.getByText("Este link é inválido, expirou ou pertence a outra conta."),
    ).toBeVisible()
  })

  test("sem sessão, o link leva ao login e volta para concluir", async ({ page }) => {
    const { pathname, search } = new URL(verificationLink)
    await page.goto(pathname + search)

    await expect(page).toHaveURL(`/login?${new URLSearchParams({ next: pathname + search })}`)
    await field(page, "E-mail").fill(account.email)
    await field(page, "Senha").fill(E2E_PASSWORD)
    await page.getByRole("button", { name: "Entrar" }).click()

    await expect(page.getByText("E-mail confirmado. Obrigado!")).toBeVisible()
    await page.getByRole("link", { name: "Ir para o painel" }).click()
    await expect(page.getByText("Verificado", { exact: true })).toBeVisible()
    await expect(page.getByText("Confirme seu e-mail")).toHaveCount(0)
  })
})
