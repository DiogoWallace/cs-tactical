import { fireEvent, render, screen, within } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { AppNav } from "./app-nav"

const pathname = vi.hoisted(() => ({ current: "/painel" }))

vi.mock("next/navigation", () => ({ usePathname: () => pathname.current }))
vi.mock("../api/auth", () => ({ logout: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateAfterSessionChange: vi.fn() }))

describe("AppNav", () => {
  beforeEach(() => {
    pathname.current = "/painel"
  })

  it("marks the current page in the main navigation", () => {
    pathname.current = "/painel/conta"
    render(<AppNav displayName="ana_cs" email="ana@example.com" />)

    const nav = screen.getByRole("navigation", { name: "Principal" })
    expect(within(nav).getByRole("link", { name: "Conta" })).toHaveAttribute("aria-current", "page")
    expect(within(nav).getByRole("link", { name: "Painel" })).not.toHaveAttribute("aria-current")
  })

  it("opens the mobile menu with the account and closes it after navigating", () => {
    render(<AppNav displayName="ana_cs" email="ana@example.com" />)

    fireEvent.click(screen.getByRole("button", { name: "Abrir menu" }))

    const menu = screen.getByRole("dialog", { name: "Menu" })
    expect(menu).toHaveTextContent("Conectado como ana_cs")
    expect(menu).toHaveTextContent("ana@example.com")
    expect(within(menu).getByRole("button", { name: "Sair" })).toBeInTheDocument()

    fireEvent.click(within(menu).getByRole("link", { name: "Conta" }))
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
  })

  it("closes the mobile menu with the close button", () => {
    render(<AppNav displayName="ana_cs" email="ana@example.com" />)

    fireEvent.click(screen.getByRole("button", { name: "Abrir menu" }))
    fireEvent.click(screen.getByRole("button", { name: "Fechar menu" }))

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
  })
})
