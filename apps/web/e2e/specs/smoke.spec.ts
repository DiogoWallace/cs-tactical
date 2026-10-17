import { expect, test } from "@playwright/test"

import { mailpitIsReachable } from "../support/mailpit"

// Valida a topologia da stack E2E: mesma origem do navegador, proxy /api do Next e Mailpit.
test("a stack E2E responde pela mesma origem", async ({ page, request }) => {
  const health = await request.get("/api/v1/health")
  expect(health.ok()).toBe(true)

  await page.goto("/login")
  await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible()

  expect(await mailpitIsReachable(request)).toBe(true)
})
