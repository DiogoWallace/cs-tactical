import { expect, test } from "@playwright/test"

import { ACCOUNTS, E2E_PASSWORD } from "../support/accounts"
import { field, login, submitLogin } from "../support/auth"
import { linkFrom, openMailbox } from "../support/mailpit"

const account = ACCOUNTS.reset
const NEW_PASSWORD = "Nova-senha-reset-456"

async function requestReset(page: import("@playwright/test").Page, email: string) {
  await page.goto("/esqueci-senha")
  await field(page, "E-mail").fill(email)
  await page.getByRole("button", { name: "Enviar link" }).click()
  const status = page.getByRole("status").filter({ hasText: "O link vale por 60 minutos" })
  await expect(status).toBeVisible()
  return (await status.textContent())?.trim()
}

test("recuperação de senha de ponta a ponta", async ({ page, request }) => {
  // A resposta não revela se a conta existe.
  const unknownMessage = await requestReset(page, "ninguem@e2e.test")

  const mailbox = await openMailbox(request, account.email)
  const knownMessage = await requestReset(page, account.email)
  expect(knownMessage).toBe(unknownMessage)

  const link = linkFrom(await mailbox.next(/Redefina sua senha/), "/redefinir-senha")
  const url = new URL(link)
  // Token e e-mail vão no fragmento (nunca enviado ao servidor) e somem da barra de endereço.
  expect(url.search).toBe("")
  expect(url.hash).toContain("token=")

  await page.goto(url.pathname + url.hash)
  await expect(page.getByText(`Nova senha para ${account.email}.`)).toBeVisible()
  expect(new URL(page.url()).hash).toBe("")

  await field(page, "Nova senha").fill(NEW_PASSWORD)
  await field(page, "Confirme a nova senha").fill(NEW_PASSWORD)
  await page.getByRole("button", { name: "Redefinir senha" }).click()
  await expect(page.getByText("Sua senha foi redefinida. Entre com a nova senha.")).toBeVisible()

  await test.step("o mesmo link não funciona de novo", async () => {
    await page.goto("/login") // sai da página para descartar o link guardado em memória
    await page.goto(url.pathname + url.hash)
    await field(page, "Nova senha").fill("Outra-senha-789")
    await field(page, "Confirme a nova senha").fill("Outra-senha-789")
    await page.getByRole("button", { name: "Redefinir senha" }).click()
    await expect(page.getByRole("link", { name: "Pedir um novo link" })).toBeVisible()
  })

  await test.step("a senha antiga deixa de valer e a nova funciona", async () => {
    await submitLogin(page, account, { password: E2E_PASSWORD })
    await expect(page.getByText("E-mail ou senha incorretos.")).toBeVisible()

    await login(page, account, { password: NEW_PASSWORD })
  })
})
