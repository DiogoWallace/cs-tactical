import { expect, test, type Page } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { ACCOUNTS } from "../support/accounts"
import { field, login } from "../support/auth"

/** O tema padrão é o escuro (theme-provider.tsx); o claro é escolhido pelo usuário. */
const THEMES = ["dark", "light"] as const

async function useTheme(page: Page, theme: (typeof THEMES)[number]) {
  await page.addInitScript((value) => window.localStorage.setItem("theme", value), theme)
}

for (const theme of THEMES) {
  test.describe(`acessibilidade (axe), tema ${theme}`, () => {
    test.beforeEach(async ({ page }) => {
      await useTheme(page, theme)
    })

    test("páginas públicas e de autenticação", async ({ page }) => {
      await page.goto("/")
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible()
      await expect(page.locator("html")).toHaveClass(new RegExp(`\\b${theme}\\b`))
      await expectNoSeriousA11yViolations(page, `home-${theme}`)

      await page.goto("/status")
      await expect(
        page.getByRole("heading", { level: 1, name: "Status da plataforma" }),
      ).toBeVisible()
      await expectNoSeriousA11yViolations(page, `status-${theme}`)

      await page.goto("/login")
      await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `login-${theme}`)

      await page.goto("/cadastro")
      // A lista de países só aparece no navegador: espera para analisar a página completa.
      await expect(page.getByRole("option", { name: "Brasil" })).toBeAttached()
      await expectNoSeriousA11yViolations(page, `cadastro-${theme}`)

      await page.goto("/esqueci-senha")
      await expect(page.getByRole("button", { name: "Enviar link" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `esqueci-senha-${theme}`)

      // Formulário de nova senha (fragmento com token fictício: só a tela é analisada).
      await page.goto("/redefinir-senha#token=fake&email=a11y%40e2e.test")
      await expect(page.getByRole("button", { name: "Redefinir senha" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `redefinir-senha-${theme}`)

      // Link incompleto: estado de erro.
      await page.goto("/redefinir-senha")
      await expect(page.getByRole("link", { name: "Pedir um novo link" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `redefinir-senha-invalido-${theme}`)
    })

    test("área logada e páginas de confirmação", async ({ page }) => {
      // Conta não verificada: o painel inclui o aviso de confirmação.
      await login(page, ACCOUNTS.a11y)
      await expect(page.getByTestId("upcoming-teams")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `painel-${theme}`)

      await page.goto("/painel/conta")
      await expect(page.getByRole("button", { name: "Salvar perfil" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `conta-${theme}`)

      await page.goto("/painel/nao-existe")
      await expect(page.getByText("Página não encontrada")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `404-${theme}`)

      // Links com assinatura inválida: estados de erro das páginas de confirmação.
      await page.goto("/verificar-email/x/y?expires=1&signature=invalida")
      await expect(page.getByText("Este link é inválido")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `verificar-email-${theme}`)

      await page.goto("/confirmar-email/x/y?expires=1&signature=invalida")
      await expect(page.getByText("Este link é inválido")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `confirmar-email-${theme}`)
    })
  })
}

test.describe("navegação por teclado", () => {
  test("login completo só com o teclado", async ({ page }) => {
    await page.goto("/login")
    await field(page, "E-mail").focus()

    await page.keyboard.type(ACCOUNTS.a11y.email)
    await page.keyboard.press("Tab")
    await expect(field(page, "Senha")).toBeFocused()
    await page.keyboard.type("e2e-Senha-Segura-123")
    await page.keyboard.press("Tab")
    await expect(page.getByRole("link", { name: "Esqueci minha senha" })).toBeFocused()
    await page.keyboard.press("Tab")
    await expect(field(page, "Manter conectado por 30 dias")).toBeFocused()
    await page.keyboard.press("Tab")
    await expect(page.getByRole("button", { name: "Entrar" })).toBeFocused()
    await page.keyboard.press("Enter")

    await expect(page).toHaveURL("/painel")
    await expect(
      page.getByRole("heading", { level: 1, name: `Olá, ${ACCOUNTS.a11y.nickname}` }),
    ).toBeVisible()
  })

  test("o foco fica visível nos controles", async ({ page }) => {
    await page.goto("/login")
    await field(page, "E-mail").focus()
    await page.keyboard.press("Tab")

    // Algum indicador de foco: contorno ou anel (box-shadow) no elemento focado.
    const focusIndicator = await field(page, "Senha").evaluate((element) => {
      const style = getComputedStyle(element)
      return style.outlineStyle !== "none" || style.boxShadow !== "none"
    })
    expect(focusIndicator).toBe(true)
  })
})
