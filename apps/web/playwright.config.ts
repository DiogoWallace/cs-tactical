import { defineConfig, devices } from "@playwright/test"

/**
 * Testes E2E. Rodam contra a stack isolada (compose.e2e.yaml), nunca contra a de desenvolvimento:
 * use `make e2e` na raiz do projeto. docs/development/e2e.md
 */
const isCI = Boolean(process.env.CI)

export default defineConfig({
  testDir: "./e2e/specs",
  outputDir: "./e2e/.results",
  forbidOnly: isCI,
  // Sem retentativas: os fluxos alteram dados (senha, e-mail) de um banco criado uma vez por
  // execução, e os limites de requisições da API continuam ativos. Repetir mascararia falhas.
  retries: 0,
  workers: isCI ? 2 : 3,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [["list"], ["html", { outputFolder: "./e2e/.report", open: "never" }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    locale: "pt-BR",
    timezoneId: "America/Sao_Paulo",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  projects: [
    {
      name: "chromium-desktop",
      use: { ...devices["Desktop Chrome"] },
      grepInvert: /@mobile/,
    },
    {
      name: "chromium-mobile",
      use: { ...devices["Pixel 7"] },
      grep: /@mobile/,
    },
  ],
})
