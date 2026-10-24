import { expect, test, type Page } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { ACCOUNTS } from "../support/accounts"
import { field, login, testId } from "../support/auth"

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

      // /status tem teste próprio, com os dois estados controlados (abaixo).

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
      await expect(testId(page, "upcoming-teams")).toBeVisible()
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

    test("/status: carregando e carregado", async ({ page }) => {
      // Os dados de saúde são lidos no servidor (Server Component); no navegador, o que chega
      // depois é o payload RSC da navegação. Ele fica retido até o skeleton ser analisado, então
      // o estado "carregando" é garantido, sem depender do tempo da API. Prefetches passam.
      const gate = await holdNavigationPayload(page, "/status")
      const skeleton = page.getByRole("status", { name: "Carregando status" })
      try {
        await page.goto("/")
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible()

        // O skeleton vem do shell estático pré-carregado; sem ele, o Next mantém a página antiga
        // até o payload chegar. Espera a resposta desse prefetch chegar antes do clique.
        const link = page.getByRole("link", { name: "Status da plataforma" })
        await link.hover()
        await expect
          .poll(gate.shellPrefetched, { message: "prefetch do shell de /status" })
          .toBe(true)

        await link.click()
        await expect(skeleton).toBeVisible()
        expect(gate.held(), "payload da navegação retido").toBe(true)
        // Durante a navegação de cliente o Next 16 remove o <title> antigo e só escreve o novo
        // quando o payload chega (comportamento do framework em todas as rotas, ~35 ms em
        // /cadastro). Só essa regra fica fora aqui; o título é conferido no estado carregado.
        await expectNoSeriousA11yViolations(page, `status-carregando-${theme}`, {
          disableRules: ["document-title"],
        })
      } finally {
        // Libera mesmo se uma asserção falhar, para o teste nunca ficar preso.
        gate.release()
      }

      await expect(skeleton).toBeHidden()
      await expect(page.getByText("/api/v1/health")).toBeVisible()
      await expect(page).toHaveTitle("Status · CS Tactical")
      await expectNoSeriousA11yViolations(page, `status-carregado-${theme}`)
    })
  })
}

// Etapa 2.7c (X6): os esqueletos pulsam (animate-pulse) só sem a preferência de movimento reduzido.
// O mesmo estado "carregando" de /status, nas duas preferências: a medida com movimento normal
// prova que a verificação enxerga a animação.
for (const reducedMotion of ["no-preference", "reduce"] as const) {
  test(`esqueleto de carregamento com movimento ${reducedMotion}`, async ({ page }) => {
    await page.emulateMedia({ reducedMotion })
    const gate = await holdNavigationPayload(page, "/status")
    const skeleton = page.getByRole("status", { name: "Carregando status" })
    try {
      await page.goto("/")
      const link = page.getByRole("link", { name: "Status da plataforma" })
      await link.hover()
      await expect
        .poll(gate.shellPrefetched, { message: "prefetch do shell de /status" })
        .toBe(true)
      await link.click()
      await expect(skeleton).toBeVisible()
      const running = await page.evaluate(
        () =>
          document
            .getAnimations()
            .filter(
              (a) =>
                a.playState === "running" &&
                ((a.effect as KeyframeEffect | null)?.target as Element | null)?.matches(
                  '[data-slot="skeleton"]',
                ),
            ).length,
      )
      if (reducedMotion === "reduce") expect(running, "esqueletos animados").toBe(0)
      else expect(running, "esqueletos animados").toBeGreaterThan(0)
    } finally {
      gate.release()
    }
  })
}

/**
 * Retém a requisição do payload RSC de uma navegação de cliente para `path` (não os prefetches)
 * até `release()`. `held()` diz se alguma requisição chegou a ser retida; `shellPrefetched()`,
 * se a resposta do prefetch do conteúdo da rota (o shell estático) já chegou. Essa resposta não
 * "termina": o Next a mantém aberta para a parte dinâmica, então o sinal é a chegada dela.
 */
async function holdNavigationPayload(page: Page, path: string) {
  let release!: () => void
  const released = new Promise<void>((resolve) => (release = resolve))
  let held = false
  let shellPrefetched = false

  page.on("response", (response) => {
    const request = response.request()
    const headers = request.headers()
    if (
      new URL(request.url()).pathname === path &&
      headers["next-router-prefetch"] &&
      !headers["next-router-segment-prefetch"] &&
      response.ok()
    ) {
      shellPrefetched = true
    }
  })

  await page.route(
    (url) => url.pathname === path,
    async (route) => {
      const headers = route.request().headers()
      if (headers["rsc"] === "1" && !headers["next-router-prefetch"]) {
        held = true
        await released
      }
      await route.continue()
    },
  )

  return { held: () => held, shellPrefetched: () => shellPrefetched, release }
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
