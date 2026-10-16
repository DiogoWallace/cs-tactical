import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { login } from "../api/auth"
import { navigateAfterSessionChange } from "../lib/navigation"
import { LoginForm } from "./login-form"

vi.mock("../api/auth", () => ({ login: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateAfterSessionChange: vi.fn() }))

let search = ""
vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams(search) }))

function fillAndSubmit({ remember = false } = {}) {
  fireEvent.change(screen.getByLabelText("E-mail"), { target: { value: "ana@example.com" } })
  fireEvent.change(screen.getByLabelText("Senha"), { target: { value: "correct-horse-9" } })
  if (remember) fireEvent.click(screen.getByLabelText(/Manter conectado/))
  fireEvent.click(screen.getByRole("button", { name: "Entrar" }))
}

describe("LoginForm", () => {
  beforeEach(() => {
    vi.mocked(login).mockReset()
    vi.mocked(navigateAfterSessionChange).mockReset()
    search = ""
  })

  it("logs in and goes to the requested page", async () => {
    search = "next=%2Fpainel%3Ftab%3Dconta"
    vi.mocked(login).mockResolvedValue({} as never)
    render(<LoginForm />)

    fillAndSubmit({ remember: true })

    await waitFor(() =>
      expect(navigateAfterSessionChange).toHaveBeenCalledWith("/painel?tab=conta"),
    )
    expect(login).toHaveBeenCalledWith({
      email: "ana@example.com",
      password: "correct-horse-9",
      remember: true,
    })
  })

  it("never follows an external next", async () => {
    search = "next=https%3A%2F%2Fevil.example"
    vi.mocked(login).mockResolvedValue({} as never)
    render(<LoginForm />)

    fillAndSubmit()

    await waitFor(() => expect(navigateAfterSessionChange).toHaveBeenCalledWith("/painel"))
  })

  it("shows invalid credentials as a general message", async () => {
    vi.mocked(login).mockRejectedValue(
      new ApiError(422, {
        message: "E-mail ou senha incorretos.",
        code: "validation_failed",
        errors: { email: ["E-mail ou senha incorretos."] },
      }),
    )
    render(<LoginForm />)

    fillAndSubmit()

    expect(await screen.findByRole("alert")).toHaveTextContent("E-mail ou senha incorretos.")
    expect(screen.getByLabelText("E-mail")).not.toHaveAttribute("aria-invalid")
    expect(navigateAfterSessionChange).not.toHaveBeenCalled()
    expect(screen.getByRole("button", { name: "Entrar" })).toBeEnabled()
  })

  it("explains the rate limit", async () => {
    vi.mocked(login).mockRejectedValue(
      new ApiError(429, { message: "Too Many Attempts.", code: "too_many_requests" }),
    )
    render(<LoginForm />)

    fillAndSubmit()

    expect(await screen.findByRole("alert")).toHaveTextContent("Muitas tentativas")
  })

  it("moves on when the user is already authenticated", async () => {
    vi.mocked(login).mockRejectedValue(
      new ApiError(409, { message: "Already authenticated.", code: "already_authenticated" }),
    )
    render(<LoginForm />)

    fillAndSubmit()

    await waitFor(() => expect(navigateAfterSessionChange).toHaveBeenCalledWith("/painel"))
  })
})
