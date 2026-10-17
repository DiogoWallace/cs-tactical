import { expect, test } from "@playwright/test"

import { field, text } from "../support/auth"
import { linkFrom, openMailbox } from "../support/mailpit"

// Um único cadastro por execução: o limite da API (3 por minuto por IP) continua valendo aqui.
test("cadastro cria a conta, entra no painel e envia a confirmação", async ({ page, request }) => {
  const email = `cadastro-${Date.now()}@e2e.test`
  const nickname = `novo_${Date.now().toString(36)}`
  const mailbox = await openMailbox(request, email)

  await page.goto("/cadastro")
  await field(page, "Nome").fill("Jogadora Nova")
  await field(page, "Apelido").fill(nickname)
  await field(page, "E-mail").fill(email)
  await field(page, "Senha").fill("Senha-cadastro-123")
  await field(page, "Confirme a senha").fill("Senha-cadastro-123")
  await field(page, "País (opcional)").selectOption("BR")
  await page.getByRole("button", { name: "Criar conta" }).click()

  await expect(page).toHaveURL("/painel")
  await expect(page.getByRole("heading", { level: 1, name: `Olá, ${nickname}` })).toBeVisible()
  await expect(text(page, "Não verificado", { exact: true })).toBeVisible()

  const message = await mailbox.next(/Confirme seu e-mail/)
  expect(linkFrom(message, "/verificar-email/")).toMatch(
    /^http:\/\/localhost:3000\/verificar-email\//,
  )
})
