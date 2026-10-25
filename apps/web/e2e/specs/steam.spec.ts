import { expect, test, type Page } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { ACCOUNTS, E2E_PASSWORD, type Account } from "../support/accounts"
import { field, login, text } from "../support/auth"

/*
| Vínculo da conta Steam (etapa M2) contra o provedor OpenID falso da stack E2E
| (infra/e2e/steam-openid, decisão S1): a ida pela navegação, a página de "login" simulada, a volta
| para /painel/conta/steam e a confirmação da API com o provedor (`check_authentication`).
*/

const STEAM_A = "76561198000000011"
const STEAM_B = "76561198000000022"
const THEMES = ["dark", "light"] as const

/** Do botão da conta até a volta da Steam, escolhendo o SteamID e autorizando ou cancelando. */
async function goThroughSteam(page: Page, steamId: string, decision: "allow" | "cancel") {
  await page.goto("/painel/conta")
  await page.getByRole("button", { name: "Vincular conta Steam" }).click()
  await expect(page).toHaveURL(/^http:\/\/steam-openid:8090\/openid\/login\?/)
  await page.getByLabel("SteamID64").fill(steamId)
  await page
    .getByRole("button", { name: decision === "allow" ? "Entrar e autorizar" : "Cancelar" })
    .click()
  // A página de volta apaga a resposta da Steam da barra de endereço.
  await expect(page).toHaveURL("/painel/conta/steam")
}

async function signIn(page: Page, account: Account) {
  await login(page, account)
}

test.describe("conta Steam", () => {
  test("vincula pela Steam, mostra o SteamID só ao dono e desvincula com a senha", async ({
    page,
  }) => {
    await signIn(page, ACCOUNTS.steam)

    await goThroughSteam(page, STEAM_A, "allow")
    await expect(text(page, "Conta Steam vinculada.")).toBeVisible()
    await expect(page.getByRole("heading", { level: 1, name: "Conta Steam" })).toBeFocused()
    await expectNoSeriousA11yViolations(page, "steam-volta-vinculada")

    await page.getByRole("link", { name: "Voltar para a conta" }).click()
    await expect(page).toHaveURL("/painel/conta")
    await expect(text(page, STEAM_A)).toBeVisible()

    for (const theme of THEMES) {
      await page.evaluate((value) => window.localStorage.setItem("theme", value), theme)
      await page.reload()
      await expect(page.locator("html")).toHaveClass(new RegExp(`\\b${theme}\\b`))
      await expect(text(page, STEAM_A)).toBeVisible()
      await expectNoSeriousA11yViolations(page, `conta-steam-vinculada-${theme}`)
    }

    await page.getByRole("button", { name: "Desvincular" }).click()
    const confirm = page.getByRole("form", { name: "Desvincular a conta Steam?" })
    await field(confirm, "Senha atual").fill("senha-errada-1")
    await confirm.getByRole("button", { name: "Confirmar desvínculo" }).click()
    await expect(field(confirm, "Senha atual")).toHaveAttribute("aria-invalid", "true")
    await expect(text(page, STEAM_A)).toBeVisible()

    await field(confirm, "Senha atual").fill(E2E_PASSWORD)
    await confirm.getByRole("button", { name: "Confirmar desvínculo" }).click()
    await expect(text(page, "Conta Steam desvinculada.")).toBeVisible()
    await expect(text(page, STEAM_A)).toHaveCount(0)

    // Depois de recarregar, continua desvinculada (vem da API).
    await page.reload()
    await expect(page.getByRole("button", { name: "Vincular conta Steam" })).toBeVisible()
  })

  test("cancelar na Steam não vincula nada", async ({ page }) => {
    await signIn(page, ACCOUNTS.steamOther)

    await goThroughSteam(page, STEAM_B, "cancel")
    await expect(
      page.getByRole("alert").filter({ hasText: "Você cancelou na Steam" }),
    ).toBeVisible()

    await page.getByRole("link", { name: "Voltar para a conta" }).click()
    await expect(page.getByRole("button", { name: "Vincular conta Steam" })).toBeVisible()
  })

  test("SteamID já vinculado a outra conta é recusado sem revelar qual", async ({
    page,
    browser,
    baseURL,
  }) => {
    // Outra conta vincula o STEAM_B primeiro, num navegador separado.
    const otherContext = await browser.newContext({ baseURL })
    const other = await otherContext.newPage()
    await signIn(other, ACCOUNTS.steamOther)
    await goThroughSteam(other, STEAM_B, "allow")
    await expect(text(other, "Conta Steam vinculada.")).toBeVisible()

    await signIn(page, ACCOUNTS.steam)
    await goThroughSteam(page, STEAM_B, "allow")
    await expect(
      page.getByRole("alert").filter({ hasText: "vinculada a outra conta" }),
    ).toBeVisible()
    await expect(page.getByText(ACCOUNTS.steamOther.email)).toHaveCount(0)
    await expect(page.getByText(ACCOUNTS.steamOther.nickname)).toHaveCount(0)

    // Desfaz, para a outra conta voltar ao estado inicial.
    await other.goto("/painel/conta")
    await other.getByRole("button", { name: "Desvincular" }).click()
    const confirm = other.getByRole("form", { name: "Desvincular a conta Steam?" })
    await field(confirm, "Senha atual").fill(E2E_PASSWORD)
    await confirm.getByRole("button", { name: "Confirmar desvínculo" }).click()
    await expect(text(other, "Conta Steam desvinculada.")).toBeVisible()
    await otherContext.close()
  })
})
