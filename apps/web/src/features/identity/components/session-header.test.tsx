import { render, screen, within } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { SiteHeaderSkeleton } from "@/components/site-header"

import type { CurrentUser } from "../types"
import { SessionHeaderView } from "./session-header"

const pathname = vi.hoisted(() => ({ current: "/academy" }))

vi.mock("next/navigation", () => ({ usePathname: () => pathname.current }))
vi.mock("../api/auth", () => ({ logout: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateAfterSessionChange: vi.fn() }))
// Leituras do servidor (cookies, /me): fora do teste do componente.
vi.mock("../api/get-session-state", () => ({ getSessionState: vi.fn() }))
vi.mock("../api/get-current-user", () => ({ getCurrentUser: vi.fn() }))

const user = {
  id: "01a146c9-8fd5-730a-95c8-e4390be8162b",
  name: "Ana",
  email: "ana@example.com",
  email_verified: true,
  profile: { nickname: "ana_cs" },
} as unknown as CurrentUser

function banner() {
  return screen.getByRole("banner")
}

describe("SessionHeaderView", () => {
  beforeEach(() => {
    pathname.current = "/academy"
  })

  it("shows the sign-in actions to visitors", () => {
    render(<SessionHeaderView session={{ kind: "visitor" }} />)

    expect(within(banner()).getByRole("link", { name: "Entrar" })).toHaveAttribute("href", "/login")
    expect(within(banner()).getByRole("link", { name: "Criar conta" })).toHaveAttribute(
      "href",
      "/cadastro",
    )
    const nav = within(banner()).getByRole("navigation", { name: "Principal" })
    expect(within(nav).getByRole("link", { name: "Academy" })).toHaveAttribute(
      "aria-current",
      "page",
    )
    expect(within(banner()).queryByRole("button", { name: "Sair" })).toBeNull()
  })

  it("shows the signed-in navigation, without sign-in actions, on public pages", () => {
    pathname.current = "/academy/cursos/fundamentos-cs2"
    render(<SessionHeaderView session={{ kind: "user", user }} />)

    expect(within(banner()).queryByRole("link", { name: "Entrar" })).toBeNull()
    expect(within(banner()).queryByRole("link", { name: "Criar conta" })).toBeNull()

    const nav = within(banner()).getByRole("navigation", { name: "Principal" })
    expect(
      within(nav)
        .getAllByRole("link")
        .map((link) => link.textContent),
    ).toEqual(["Painel", "Academy", "Meu progresso", "Conta"])
    expect(within(nav).getByRole("link", { name: "Academy" })).toHaveAttribute(
      "aria-current",
      "page",
    )
    expect(within(banner()).getByText("ana_cs")).toBeInTheDocument()
    expect(within(banner()).getByRole("button", { name: "Sair" })).toBeInTheDocument()
    // A marca leva ao painel, como na área logada.
    expect(within(banner()).getByRole("link", { name: "CS Tactical" })).toHaveAttribute(
      "href",
      "/painel",
    )
  })

  it("does not claim the user is a visitor when the API did not answer", () => {
    render(<SessionHeaderView session={{ kind: "unknown" }} />)

    expect(within(banner()).queryByRole("link", { name: "Entrar" })).toBeNull()
    expect(within(banner()).queryByRole("link", { name: "Criar conta" })).toBeNull()
    expect(within(banner()).getByRole("link", { name: "Painel" })).toHaveAttribute(
      "href",
      "/painel",
    )
    // Nenhum dado de conta sem a confirmação da API.
    expect(within(banner()).queryByRole("button", { name: "Sair" })).toBeNull()
  })

  it("renders a neutral placeholder while the session is resolved", () => {
    render(<SiteHeaderSkeleton />)

    expect(banner()).toHaveAttribute("aria-busy", "true")
    expect(
      within(banner())
        .getAllByRole("link")
        .map((link) => link.textContent),
    ).toEqual(["CS Tactical"])
  })
})
