import { expect, test, type Page } from "@playwright/test"

import { expectNoSeriousA11yViolations } from "../support/a11y"
import { text } from "../support/auth"

/**
 * Academy pública (etapa 2.3) contra a API real da stack E2E, com o conteúdo de
 * e2e/fixtures/academy (importado pelo scripts/e2e.sh; nada do banco de desenvolvimento).
 */
const COURSE = "/academy/cursos/fundamentos-e2e"
const LESSON = `${COURSE}/aulas/counter-strafe`
const VIDEO_LESSON = `${COURSE}/aulas/peek-seguro`

/** Textos de rascunhos e do gabarito: nunca podem chegar ao navegador. */
const SECRETS = [
  "CURSO-RASCUNHO-SECRETO",
  "MODULO-RASCUNHO-SECRETO",
  "AULA-RASCUNHO-SECRETA",
  "OPCAO-CERTA-E2E",
  "OPCAO-ERRADA-E2E",
  "EXPLICACAO-SECRETA-E2E",
]

/** O player do YouTube nunca é carregado de verdade: os testes não dependem da internet. */
async function stubYoutube(page: Page) {
  const requests: string[] = []
  await page.route("https://www.youtube-nocookie.com/**", async (route) => {
    requests.push(route.request().url())
    await route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" })
  })
  return requests
}

async function expectNoSecrets(page: Page) {
  const html = await page.content()
  for (const secret of SECRETS) expect(html, secret).not.toContain(secret)
}

test.describe("Academy: visitante", () => {
  test("catálogo com cursos publicados e filtro por nível", async ({ page }) => {
    await page.goto("/academy")

    await expect(page).toHaveTitle("Academy · CS Tactical")
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Aprenda CS2")
    const cards = page.getByRole("article")
    await expect(cards).toHaveCount(2)
    await expect(cards.nth(0).getByRole("heading", { name: "Fundamentos E2E" })).toBeVisible()
    await expect(cards.nth(0)).toContainText("Iniciante")
    await expect(cards.nth(0)).toContainText("2 módulos")
    await expect(cards.nth(0)).toContainText("3 aulas")
    await expect(cards.nth(1)).toContainText("Táticas avançadas")
    await expectNoSecrets(page)

    // A capa vem da rota de mídia da API, pela mesma origem.
    const cover = cards.nth(0).locator("img")
    await expect(cover).toHaveAttribute("src", /^\/api\/v1\/academy\/media\/[0-9a-f]{64}\.png$/)
    await cover.scrollIntoViewIfNeeded()
    await expect
      .poll(() => cover.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0)

    const filter = page.getByRole("navigation", { name: "Filtrar por nível" })
    await filter.getByRole("link", { name: "Avançado" }).click()
    await expect(page).toHaveURL("/academy?level=advanced")
    await expect(filter.getByRole("link", { name: "Avançado" })).toHaveAttribute(
      "aria-current",
      "page",
    )
    await expect(page.getByRole("article")).toHaveCount(1)
    await expect(page.getByRole("article")).toContainText("Táticas avançadas")

    // O único curso intermediário é rascunho: filtro vazio, sem revelar o rascunho.
    await filter.getByRole("link", { name: "Intermediário" }).click()
    await expect(text(page, "Nenhum curso de nível intermediário")).toBeVisible()
    await expectNoSecrets(page)
    await page.getByRole("link", { name: "Ver todos os cursos" }).click()
    await expect(page).toHaveURL("/academy")
    await expect(page.getByRole("article")).toHaveCount(2)
  })

  test("curso com módulos e aulas públicos, em ordem", async ({ page }) => {
    await page.goto("/academy")
    await page.getByRole("link", { name: "Fundamentos E2E" }).click()

    await expect(page).toHaveURL(COURSE)
    await expect(page.getByRole("heading", { level: 1, name: "Fundamentos E2E" })).toBeVisible()
    const content = page.getByRole("region", { name: "Conteúdo do curso" })
    await expect(content.getByRole("heading", { level: 3 })).toHaveText([
      "Módulo 1: Movimentação",
      "Módulo 2: Mira",
    ])
    await expect(content.getByRole("link")).toHaveCount(3)
    await expect(content.getByRole("link").nth(0)).toContainText("Counter-strafe")
    await expect(content.getByRole("link").nth(0)).toContainText("Quiz")
    await expectNoSecrets(page)
    // Sem filtro de visibilidade de propósito: o módulo em rascunho não pode estar nem escondido.
    await expect(page.getByText("Compras")).toHaveCount(0)

    await page.getByRole("link", { name: "Começar pela primeira aula" }).click()
    await expect(page).toHaveURL(LESSON)
  })

  test("aula com Markdown, imagem, callout e quiz, e navegação entre aulas", async ({ page }) => {
    await page.goto(LESSON)

    await expect(page).toHaveTitle("Counter-strafe · Fundamentos E2E · CS Tactical")
    await expect(page.getByRole("heading", { level: 1, name: "Counter-strafe" })).toBeVisible()
    await expect(text(page, "Aula 1 de 3")).toBeVisible()

    const article = page.getByRole("article")
    await expect(
      article.getByRole("heading", { level: 2, name: "Por que parar antes de atirar" }),
    ).toBeVisible()
    await expect(article.locator('[data-block="markdown"] li')).toHaveText([
      "Solte a tecla do movimento",
      "Toque a tecla oposta",
    ])
    await expect(article.locator("pre code")).toContainText('bind "mouse4" "+left"')
    const external = article.getByRole("link", { name: /guia externo/ })
    await expect(external).toHaveAttribute("href", "https://example.com/guia")
    await expect(external).toHaveAttribute("target", "_blank")
    await expect(external).toHaveAttribute("rel", "noopener noreferrer")
    await expect(article.getByRole("link", { name: "catálogo" })).toHaveAttribute(
      "href",
      "/academy",
    )
    await expect(article.locator("script")).toHaveCount(0)

    const image = article.getByRole("img", { name: "Gráfico da velocidade caindo a zero" })
    await image.scrollIntoViewIfNeeded()
    await expect
      .poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0)
    await expect(
      text(article, "A velocidade cai a zero logo depois da tecla oposta."),
    ).toBeVisible()

    await expect(article.getByRole("note", { name: "Treine offline" })).toContainText("Dica")

    const quiz = article.getByRole("region", { name: "Teste rápido E2E" })
    await expect(quiz).toContainText("2 perguntas")
    await expect(quiz).toContainText("Aprovação com 80%")
    await expect(quiz).toContainText("2 tentativas")
    await expect(quiz.getByRole("button")).toHaveCount(0)
    await expectNoSecrets(page)

    const sidebar = page.getByRole("complementary", { name: "Conteúdo do curso" })
    await expect(sidebar.getByRole("link", { name: /^Counter-strafe/ })).toHaveAttribute(
      "aria-current",
      "page",
    )
    await expect(sidebar.getByRole("link")).toHaveCount(4) // título do curso + 3 aulas públicas

    const pager = page.getByRole("navigation", { name: "Navegação entre aulas" })
    await expect(pager.getByRole("link", { name: /Aula anterior/ })).toHaveCount(0)
    await pager.getByRole("link", { name: /Próxima aula/ }).click()
    await expect(page).toHaveURL(VIDEO_LESSON)
    await expect(page.getByRole("heading", { level: 1, name: "Peek seguro" })).toBeVisible()

    // Do fim do módulo 1 para o módulo 2 (a aula em rascunho fica de fora).
    await page
      .getByRole("navigation", { name: "Navegação entre aulas" })
      .getByRole("link", { name: /Próxima aula/ })
      .click()
    await expect(page).toHaveURL(`${COURSE}/aulas/posicionamento`)
    await expect(text(page, "Aula 3 de 3")).toBeVisible()
    await expect(
      page
        .getByRole("navigation", { name: "Navegação entre aulas" })
        .getByRole("link", { name: /Próxima aula/ }),
    ).toHaveCount(0)
  })

  test("vídeo do YouTube só carrega depois do clique, pelo domínio sem cookies", async ({
    page,
  }) => {
    const youtube = await stubYoutube(page)
    await page.goto(VIDEO_LESSON)

    const play = page.getByRole("button", {
      name: "Assistir ao vídeo: Demonstração do peek seguro",
    })
    await expect(play).toBeVisible()
    await expect(page.locator("iframe")).toHaveCount(0)
    expect(youtube).toEqual([])

    await play.click()
    const frame = page.locator('iframe[title="Demonstração do peek seguro"]')
    await expect(frame).toHaveAttribute(
      "src",
      "https://www.youtube-nocookie.com/embed/AbCdEfGhIjK?autoplay=1&rel=0&start=5",
    )
    await expect(frame).toHaveAttribute(
      "sandbox",
      "allow-scripts allow-same-origin allow-presentation",
    )
    await expect.poll(() => youtube.length).toBeGreaterThan(0)

    // Imagem decorativa: presente, mas fora da árvore de acessibilidade.
    await expect(page.locator('article img[alt=""]')).toHaveCount(1)
    await expect(page.getByRole("note", { name: "Cuidado com o ângulo aberto" })).toContainText(
      "Atenção",
    )
  })

  test("conteúdo indisponível mostra a página 404 da Academy, sem indexação", async ({ page }) => {
    for (const path of [
      `${COURSE}/aulas/aula-rascunho`, // aula em rascunho
      `${COURSE}/aulas/compras`, // aula publicada em módulo rascunho
      "/academy/cursos/curso-rascunho", // curso rascunho
      "/academy/cursos/curso-rascunho/aulas/aula",
      "/academy/cursos/taticas-avancadas/aulas/counter-strafe", // aula de outro curso
      "/academy/cursos/nao-existe",
      "/academy/cursos/Maiusculas",
    ]) {
      await page.goto(path)
      await expect(
        page.getByRole("heading", { level: 1, name: "Conteúdo não encontrado" }),
        path,
      ).toBeVisible()
      // O Next injeta o próprio `noindex` e o generateMetadata também; todos têm de bloquear.
      const robots = await page
        .locator('meta[name="robots"]')
        .evaluateAll((metas) => metas.map((meta) => meta.getAttribute("content") ?? ""))
      expect(robots.length, path).toBeGreaterThan(0)
      for (const content of robots) expect(content, path).toContain("noindex")
      await expectNoSecrets(page)
    }

    await page.getByRole("link", { name: "Ver os cursos da Academy" }).click()
    await expect(page).toHaveURL("/academy")
  })
})

test.describe("Academy: CSP", () => {
  test("só a página de aula libera o player do YouTube", async ({ request }) => {
    const lesson = (await request.get(VIDEO_LESSON)).headers()
    expect(lesson["content-security-policy"]).toContain(
      "frame-src https://www.youtube-nocookie.com;",
    )
    expect(lesson["content-security-policy-report-only"]).toContain(
      "frame-src https://www.youtube-nocookie.com;",
    )
    expect(lesson["content-security-policy"]).not.toContain("unsafe-eval")

    for (const path of ["/academy", COURSE, "/"]) {
      const headers = (await request.get(path)).headers()
      expect(headers["content-security-policy"], path).toContain("frame-src 'none'")
    }
  })

  test("a CSP aplicada não bloqueia nada na Academy, inclusive o player", async ({ page }) => {
    await stubYoutube(page)
    await page.addInitScript(() => {
      const store: { disposition: string; directive: string; blocked: string }[] = []
      Object.defineProperty(window, "__cspViolations", { value: store })
      document.addEventListener("securitypolicyviolation", (event) => {
        store.push({
          disposition: event.disposition,
          directive: event.effectiveDirective,
          blocked: event.blockedURI,
        })
      })
    })
    const enforced = async () =>
      (
        await page.evaluate(
          () =>
            (window as unknown as { __cspViolations: { disposition: string }[] }).__cspViolations,
        )
      ).filter((violation) => violation.disposition === "enforce")

    for (const path of ["/academy", COURSE, LESSON]) {
      await page.goto(path)
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible()
      expect(await enforced(), path).toEqual([])
    }
    await page.goto(VIDEO_LESSON)
    await page.getByRole("button", { name: /Assistir ao vídeo/ }).click()
    await expect(page.locator("iframe")).toBeVisible()
    expect(await enforced(), "player").toEqual([])
  })
})

test.describe("Academy: acessibilidade e teclado", () => {
  for (const theme of ["dark", "light"] as const) {
    test(`axe nas páginas da Academy, tema ${theme}`, async ({ page }) => {
      await stubYoutube(page)
      await page.addInitScript((value) => window.localStorage.setItem("theme", value), theme)

      await page.goto("/academy")
      await expect(page.getByRole("article").first()).toBeVisible()
      await expect(page.locator("html")).toHaveClass(new RegExp(`\\b${theme}\\b`))
      await expectNoSeriousA11yViolations(page, `academy-catalogo-${theme}`)

      await page.goto("/academy?level=intermediate")
      await expect(text(page, "Nenhum curso de nível intermediário")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `academy-filtro-vazio-${theme}`)

      await page.goto(COURSE)
      await expect(page.getByRole("region", { name: "Conteúdo do curso" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `academy-curso-${theme}`)

      await page.goto(LESSON)
      await expect(page.getByRole("region", { name: "Teste rápido E2E" })).toBeVisible()
      await expectNoSeriousA11yViolations(page, `academy-aula-${theme}`)

      await page.goto(VIDEO_LESSON)
      await page.getByRole("button", { name: /Assistir ao vídeo/ }).click()
      await expect(page.locator("iframe")).toBeVisible()
      await expectNoSeriousA11yViolations(page, `academy-aula-video-${theme}`)

      await page.goto(`${COURSE}/aulas/aula-rascunho`)
      await expect(
        page.getByRole("heading", { level: 1, name: "Conteúdo não encontrado" }),
      ).toBeVisible()
      await expectNoSeriousA11yViolations(page, `academy-404-${theme}`)
    })
  }

  test("do catálogo à aula só com o teclado, com foco visível", async ({ page }) => {
    await page.goto("/academy")
    const filterLink = page
      .getByRole("navigation", { name: "Filtrar por nível" })
      .getByRole("link", { name: "Todos" })
    await filterLink.focus()
    const focusVisible = await filterLink.evaluate((element) => {
      const style = getComputedStyle(element)
      return style.outlineStyle !== "none" || style.boxShadow !== "none"
    })
    expect(focusVisible).toBe(true)

    // Do último filtro, o próximo Tab vai para o primeiro curso.
    await page.getByRole("link", { name: "Avançado" }).focus()
    await page.keyboard.press("Tab")
    await expect(page.getByRole("link", { name: "Fundamentos E2E" })).toBeFocused()
    await page.keyboard.press("Enter")
    await expect(page).toHaveURL(COURSE)

    await page.getByRole("link", { name: "Começar pela primeira aula" }).focus()
    await page.keyboard.press("Enter")
    await expect(page).toHaveURL(LESSON)
    await expect(page.getByRole("heading", { level: 1, name: "Counter-strafe" })).toBeVisible()
  })
})

// Projeto chromium-mobile (Pixel 7).
test.describe("Academy: celular", () => {
  test("aula legível no celular, com a lista de aulas recolhível @mobile", async ({ page }) => {
    await page.goto(LESSON)
    await expect(page.getByRole("heading", { level: 1, name: "Counter-strafe" })).toBeVisible()

    // Sem rolagem horizontal; o painel lateral do desktop fica escondido.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(0)
    await expect(page.getByRole("complementary", { name: "Conteúdo do curso" })).toBeHidden()

    const toggle = text(page, "Aulas do curso", { exact: true })
    const list = page.getByRole("navigation", { name: "Aulas do curso" })
    await expect(list).toBeHidden()
    await toggle.tap()
    await expect(list).toBeVisible()
    await expect(list.getByRole("link", { name: /^Counter-strafe/ })).toHaveAttribute(
      "aria-current",
      "page",
    )

    await list.getByRole("link", { name: "Posicionamento da mira" }).tap()
    await expect(page).toHaveURL(`${COURSE}/aulas/posicionamento`)
    await expect(
      page.getByRole("heading", { level: 1, name: "Posicionamento da mira" }),
    ).toBeVisible()
  })

  test("catálogo e curso em uma coluna, sem rolagem horizontal @mobile", async ({ page }) => {
    for (const path of ["/academy", COURSE]) {
      await page.goto(path)
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible()
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow, path).toBeLessThanOrEqual(0)
    }

    await page.goto("/academy")
    const [first, second] = await Promise.all([
      page.getByRole("article").nth(0).boundingBox(),
      page.getByRole("article").nth(1).boundingBox(),
    ])
    expect(second!.y).toBeGreaterThan(first!.y + first!.height - 1)
    await expect(page.getByRole("link", { name: "Academy" })).toBeVisible()
  })
})
