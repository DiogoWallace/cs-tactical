import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import NotFound from "../not-found"

describe("NotFound", () => {
  it("explains the problem and links back home", () => {
    render(<NotFound />)

    expect(screen.getByText("Página não encontrada")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Voltar ao início" })).toHaveAttribute("href", "/")
  })
})
