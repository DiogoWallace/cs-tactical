import { render, screen } from "@testing-library/react"
import { describe, expect, it, vi } from "vitest"

import { HealthOverviewSkeleton } from "./health-overview"

// O skeleton não consulta a API; o mock só evita carregar o módulo server-only.
vi.mock("@/features/system/api/get-health", () => ({ getHealth: vi.fn() }))

describe("HealthOverviewSkeleton", () => {
  it("is announced as a busy status region with an accessible name", () => {
    render(<HealthOverviewSkeleton />)

    // Um aria-label sem papel num div é proibido (axe aria-prohibited-attr).
    const region = screen.getByRole("status", { name: "Carregando status" })
    expect(region).toHaveAttribute("aria-busy", "true")
  })
})
