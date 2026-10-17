import { expect, test } from "@playwright/test"

import { ACCOUNTS } from "../support/accounts"
import { login } from "../support/auth"

// Roda só no projeto chromium-mobile (tag @mobile).
test("menu do celular navega e sai @mobile", async ({ page }) => {
  await login(page, ACCOUNTS.mobile)

  const openMenu = page.getByRole("button", { name: "Abrir menu" })
  await expect(openMenu).toBeVisible()
  // Os links do cabeçalho de desktop ficam escondidos no celular.
  await expect(page.getByRole("button", { name: "Sair" })).toBeHidden()

  await openMenu.click()
  const menu = page.getByRole("dialog", { name: "Menu" })
  await expect(menu).toBeVisible()
  await expect(menu).toContainText(ACCOUNTS.mobile.email)

  await page.keyboard.press("Escape")
  await expect(menu).toBeHidden()
  await expect(openMenu).toBeFocused()

  await openMenu.click()
  await menu.getByRole("link", { name: "Conta" }).click()
  await expect(page).toHaveURL("/painel/conta")
  await expect(menu).toBeHidden()
  await expect(page.getByRole("heading", { level: 1, name: "Conta" })).toBeVisible()

  await openMenu.click()
  await expect(menu.getByRole("link", { name: "Conta" })).toHaveAttribute("aria-current", "page")
  await menu.getByRole("button", { name: "Sair" }).click()
  await expect(page).toHaveURL("/login")
})
