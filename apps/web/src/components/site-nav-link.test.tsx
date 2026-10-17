import { render, screen } from "@testing-library/react"
import { describe, expect, it, vi } from "vitest"

import { SiteNavLink } from "./site-nav-link"

const pathname = vi.hoisted(() => ({ current: "/" }))
vi.mock("next/navigation", () => ({ usePathname: () => pathname.current }))

describe("SiteNavLink", () => {
  it.each([
    ["/academy", true],
    ["/academy/cursos/fundamentos-cs2", true],
    ["/academyx", false],
    ["/", false],
  ])("on %s marks the Academy section: %s", (path, active) => {
    pathname.current = path
    render(<SiteNavLink href="/academy">Academy</SiteNavLink>)

    const link = screen.getByRole("link", { name: "Academy" })
    if (active) expect(link).toHaveAttribute("aria-current", "page")
    else expect(link).not.toHaveAttribute("aria-current")
  })
})
