import { expect, test } from "@playwright/test"

import { ACCOUNTS, E2E_PASSWORD } from "../support/accounts"
import { field, login, submitLogin } from "../support/auth"
import { linkFrom, openMailbox } from "../support/mailpit"

test.describe("conta", () => {
  test("atualizar o perfil muda o apelido no cabeçalho", async ({ page }) => {
    await login(page, ACCOUNTS.profile)
    await page.goto("/painel/conta")

    await field(page, "Apelido").fill("e2e_perfil_novo")
    await field(page, "Bio (opcional)").fill("Entry fragger nas horas vagas.")
    await page.getByRole("button", { name: "Salvar perfil" }).click()

    await expect(page.getByText("Perfil atualizado.")).toBeVisible()
    await expect(page.getByRole("banner").getByText("e2e_perfil_novo")).toBeVisible()

    await page.reload()
    await expect(field(page, "Apelido")).toHaveValue("e2e_perfil_novo")
    await expect(field(page, "Bio (opcional)")).toHaveValue("Entry fragger nas horas vagas.")
  })

  test("trocar o e-mail exige confirmação no novo endereço e avisa o antigo", async ({
    page,
    request,
  }) => {
    const account = ACCOUNTS.email
    const newEmail = "email-novo@e2e.test"
    await login(page, account)
    await page.goto("/painel/conta")

    const newMailbox = await openMailbox(request, newEmail)
    const oldMailbox = await openMailbox(request, account.email)

    const emailForm = page.locator("form").filter({ hasText: "Enviar link de confirmação" })
    await field(emailForm, "Novo e-mail").fill(newEmail)
    await field(emailForm, "Senha atual").fill(E2E_PASSWORD)
    await emailForm.getByRole("button", { name: "Enviar link de confirmação" }).click()
    await expect(page.getByText(`Enviamos um link para ${newEmail}`)).toBeVisible()
    // Até o clique no link, o e-mail da conta não muda.
    await expect(page.getByText(`E-mail atual: ${account.email}`)).toBeVisible()

    const link = new URL(
      linkFrom(await newMailbox.next(/Confirme seu novo e-mail/), "/confirmar-email/"),
    )
    await page.goto(link.pathname + link.search)
    await expect(page.getByText(`O e-mail da sua conta agora é ${newEmail}`)).toBeVisible()

    await oldMailbox.next(/O e-mail da sua conta foi alterado/)

    await test.step("o login passa a ser com o novo e-mail", async () => {
      await page.goto("/painel")
      await page.getByRole("button", { name: "Sair" }).click()
      await expect(page).toHaveURL("/login")
      await submitLogin(page, account)
      await expect(page.getByText("E-mail ou senha incorretos.")).toBeVisible()
      await login(page, { ...account, email: newEmail })
    })
  })

  test("trocar a senha mantém esta sessão e encerra as outras", async ({ browser, baseURL }) => {
    const account = ACCOUNTS.password
    const newPassword = "Senha-trocada-789"

    const current = await browser.newContext({ baseURL })
    const other = await browser.newContext({ baseURL })
    const page = await current.newPage()
    const otherPage = await other.newPage()
    await login(page, account)
    await login(otherPage, account)

    await page.goto("/painel/conta")
    const passwordCard = page.locator("form").filter({ hasText: "Alterar senha" })
    await field(passwordCard, "Senha atual").fill(E2E_PASSWORD)
    await field(passwordCard, "Nova senha").fill(newPassword)
    await field(passwordCard, "Confirme a nova senha").fill(newPassword)
    await passwordCard.getByRole("button", { name: "Alterar senha" }).click()
    await expect(
      page.getByText("Senha alterada. As outras sessões abertas foram encerradas."),
    ).toBeVisible()

    // Esta sessão continua válida.
    await page.goto("/painel")
    await expect(
      page.getByRole("heading", { level: 1, name: `Olá, ${account.nickname}` }),
    ).toBeVisible()

    // A outra foi encerrada.
    await otherPage.goto("/painel")
    await expect(otherPage).toHaveURL(`/login?next=${encodeURIComponent("/painel")}`)

    await current.close()
    await other.close()

    const fresh = await browser.newContext({ baseURL })
    await login(await fresh.newPage(), account, { password: newPassword })
    await fresh.close()
  })
})
