import { render, screen } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { confirmEmailChange } from "../api/account"
import { ConfirmEmailChangeStatus } from "./confirm-email-change-status"

vi.mock("../api/account", () => ({ confirmEmailChange: vi.fn() }))
vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "u1", hash: "abc" }),
  useSearchParams: () => new URLSearchParams("expires=1792191157&signature=f00d"),
}))

describe("ConfirmEmailChangeStatus", () => {
  beforeEach(() => {
    vi.mocked(confirmEmailChange).mockReset()
  })

  it("calls the API with the same signed path and shows the new address", async () => {
    vi.mocked(confirmEmailChange).mockResolvedValue({ email: "nova@example.com" } as never)
    render(<ConfirmEmailChangeStatus />)

    expect(await screen.findByText("nova@example.com")).toBeInTheDocument()
    expect(confirmEmailChange).toHaveBeenCalledWith(
      "/api/v1/me/email/confirm/u1/abc?expires=1792191157&signature=f00d",
    )
  })

  it("explains an invalid, expired or replaced link", async () => {
    vi.mocked(confirmEmailChange).mockImplementation(async () => {
      throw new ApiError(403, { message: "Invalid signature.", code: "forbidden" })
    })
    render(<ConfirmEmailChangeStatus />)

    expect(await screen.findByRole("alert")).toHaveTextContent("inválido, expirou")
    expect(screen.getByRole("link", { name: "Pedir a troca de novo" })).toHaveAttribute(
      "href",
      "/painel/conta",
    )
  })

  it("explains that the address was taken in the meantime", async () => {
    vi.mocked(confirmEmailChange).mockImplementation(async () => {
      throw new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: { email: ["Este e-mail já está em uso."] },
      })
    })
    render(<ConfirmEmailChangeStatus />)

    expect(await screen.findByRole("alert")).toHaveTextContent("Este e-mail já está em uso.")
  })
})
