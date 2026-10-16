import { render, screen } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { verifyEmail } from "../api/auth"
import { VerifyEmailStatus } from "./verify-email-status"

vi.mock("../api/auth", () => ({ verifyEmail: vi.fn(), resendVerification: vi.fn() }))
vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "01a1-uuid", hash: "abc123" }),
  useSearchParams: () => new URLSearchParams("expires=1792150612&signature=f00d"),
}))

describe("VerifyEmailStatus", () => {
  beforeEach(() => {
    vi.mocked(verifyEmail).mockReset()
  })

  it("calls the API with the same signed path and query", async () => {
    vi.mocked(verifyEmail).mockResolvedValue()
    render(<VerifyEmailStatus />)

    expect(await screen.findByText("E-mail confirmado. Obrigado!")).toBeInTheDocument()
    expect(verifyEmail).toHaveBeenCalledOnce()
    expect(verifyEmail).toHaveBeenCalledWith(
      "/api/v1/auth/email/verify/01a1-uuid/abc123?expires=1792150612&signature=f00d",
    )
    expect(screen.getByRole("link", { name: "Ir para o painel" })).toHaveAttribute(
      "href",
      "/painel",
    )
  })

  it("offers a new link when the signature is invalid or expired", async () => {
    vi.mocked(verifyEmail).mockImplementation(async () => {
      throw new ApiError(403, { message: "Invalid signature.", code: "forbidden" })
    })
    render(<VerifyEmailStatus />)

    expect(await screen.findByRole("alert")).toHaveTextContent("inválido, expirou")
    expect(screen.getByRole("button", { name: /Reenviar e-mail/ })).toBeInTheDocument()
  })

  it("stays quiet while the client sends the user to the login", async () => {
    vi.mocked(verifyEmail).mockImplementation(async () => {
      throw new ApiError(401, { message: "Unauthenticated.", code: "unauthenticated" })
    })
    render(<VerifyEmailStatus />)

    await vi.waitFor(() => expect(verifyEmail).toHaveBeenCalled())
    expect(screen.queryByRole("alert")).not.toBeInTheDocument()
    expect(screen.getByRole("status")).toHaveTextContent("Confirmando seu e-mail")
  })
})
