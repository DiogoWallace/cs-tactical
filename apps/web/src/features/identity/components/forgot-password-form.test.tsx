import { fireEvent, render, screen } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { requestPasswordReset } from "../api/auth"
import { ForgotPasswordForm } from "./forgot-password-form"

vi.mock("../api/auth", () => ({ requestPasswordReset: vi.fn() }))

function submit(email: string) {
  fireEvent.change(screen.getByLabelText("E-mail"), { target: { value: email } })
  fireEvent.click(screen.getByRole("button", { name: "Enviar link" }))
}

describe("ForgotPasswordForm", () => {
  beforeEach(() => {
    vi.mocked(requestPasswordReset).mockReset()
  })

  it("shows the same confirmation the API gives for any e-mail", async () => {
    vi.mocked(requestPasswordReset).mockResolvedValue(
      "Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.",
    )
    render(<ForgotPasswordForm />)

    submit("ana@example.com")

    expect(await screen.findByRole("status")).toHaveTextContent(
      "Se houver uma conta com este e-mail, enviaremos um link",
    )
    expect(requestPasswordReset).toHaveBeenCalledWith("ana@example.com")
    expect(screen.queryByRole("button", { name: "Enviar link" })).not.toBeInTheDocument()
  })

  it("shows a validation error on the field", async () => {
    vi.mocked(requestPasswordReset).mockImplementation(async () => {
      throw new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: { email: ["Informe um e-mail válido."] },
      })
    })
    render(<ForgotPasswordForm />)

    submit("x")

    expect(await screen.findByText("Informe um e-mail válido.")).toBeInTheDocument()
    expect(screen.getByLabelText("E-mail")).toHaveAttribute("aria-invalid", "true")
  })

  it("explains the rate limit", async () => {
    vi.mocked(requestPasswordReset).mockImplementation(async () => {
      throw new ApiError(429, { message: "Too Many Attempts.", code: "too_many_requests" })
    })
    render(<ForgotPasswordForm />)

    submit("ana@example.com")

    expect(await screen.findByRole("alert")).toHaveTextContent("Muitas tentativas")
  })
})
