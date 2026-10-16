import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { resetPassword } from "../api/auth"
import { parseLink, ResetPasswordForm } from "./reset-password-form"

vi.mock("../api/auth", () => ({ resetPassword: vi.fn() }))

function openLink(hash: string) {
  window.history.replaceState(null, "", `/redefinir-senha${hash}`)
}

function submit(password = "new-horse-77", confirmation = password) {
  fireEvent.change(screen.getByLabelText("Nova senha"), { target: { value: password } })
  fireEvent.change(screen.getByLabelText("Confirme a nova senha"), {
    target: { value: confirmation },
  })
  fireEvent.click(screen.getByRole("button", { name: "Redefinir senha" }))
}

describe("parseLink", () => {
  it("reads token and e-mail from the fragment", () => {
    expect(parseLink("#token=abc&email=ana%40example.com")).toEqual({
      token: "abc",
      email: "ana@example.com",
    })
  })

  it.each(["", "#", "#token=abc", "#email=ana%40example.com", null])("rejects %j", (hash) => {
    expect(parseLink(hash)).toBeNull()
  })
})

describe("ResetPasswordForm", () => {
  beforeEach(() => {
    vi.mocked(resetPassword).mockReset()
  })
  afterEach(() => window.history.replaceState(null, "", "/"))

  it("sends token, e-mail and the new password from the link", async () => {
    openLink("#token=abc123&email=ana%40example.com")
    vi.mocked(resetPassword).mockResolvedValue("ok")
    render(<ResetPasswordForm />)

    expect(screen.getByText("ana@example.com")).toBeInTheDocument()
    submit()

    expect(await screen.findByRole("status")).toHaveTextContent("Sua senha foi redefinida")
    expect(resetPassword).toHaveBeenCalledWith({
      token: "abc123",
      email: "ana@example.com",
      password: "new-horse-77",
      password_confirmation: "new-horse-77",
    })
    expect(screen.getByRole("link", { name: "Entrar" })).toHaveAttribute("href", "/login")
  })

  it("offers a new link when the token is invalid, expired or used", async () => {
    openLink("#token=used&email=ana%40example.com")
    vi.mocked(resetPassword).mockImplementation(async () => {
      throw new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: { token: ["Este link de redefinição é inválido ou expirou. Solicite um novo."] },
      })
    })
    render(<ResetPasswordForm />)

    submit()

    expect(await screen.findByRole("alert")).toHaveTextContent("inválido ou expirou")
    expect(screen.getByRole("link", { name: "Pedir um novo link" })).toHaveAttribute(
      "href",
      "/esqueci-senha",
    )
  })

  it("keeps the form when only the password is rejected", async () => {
    openLink("#token=abc&email=ana%40example.com")
    vi.mocked(resetPassword).mockImplementation(async () => {
      throw new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: { password: ["O campo senha deve ter pelo menos 8 caracteres."] },
      })
    })
    render(<ResetPasswordForm />)

    submit("short")

    expect(await screen.findByText("O campo senha deve ter pelo menos 8 caracteres.")).toBeVisible()
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "Redefinir senha" })).toBeEnabled(),
    )
  })

  it("explains an incomplete link without calling the API", () => {
    openLink("#token=abc")
    render(<ResetPasswordForm />)

    expect(screen.getByRole("alert")).toHaveTextContent("incompleto")
    expect(resetPassword).not.toHaveBeenCalled()
  })
})
