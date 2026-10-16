import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { register } from "../api/auth"
import { navigateAfterSessionChange } from "../lib/navigation"
import { RegisterForm } from "./register-form"

vi.mock("../api/auth", () => ({ register: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateAfterSessionChange: vi.fn() }))
vi.mock("../lib/countries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../lib/countries")>()),
  detectTimezone: () => "America/Sao_Paulo",
}))

function fill(label: string | RegExp, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } })
}

function fillValidForm() {
  fill("Nome", "Ana Souza")
  fill("Apelido", "AnaAWP")
  fill("E-mail", "ana@example.com")
  fill("Senha", "correct-horse-9")
  fill("Confirme a senha", "correct-horse-9")
}

describe("RegisterForm", () => {
  beforeEach(() => {
    vi.mocked(register).mockReset()
    vi.mocked(navigateAfterSessionChange).mockReset()
  })

  it("creates the account with the browser timezone and goes to the dashboard", async () => {
    vi.mocked(register).mockResolvedValue({} as never)
    render(<RegisterForm />)

    fillValidForm()
    fill(/País/, "BR")
    fireEvent.click(screen.getByRole("button", { name: "Criar conta" }))

    await waitFor(() => expect(navigateAfterSessionChange).toHaveBeenCalledWith("/painel"))
    expect(register).toHaveBeenCalledWith({
      name: "Ana Souza",
      nickname: "AnaAWP",
      email: "ana@example.com",
      password: "correct-horse-9",
      password_confirmation: "correct-horse-9",
      country_code: "BR",
      timezone: "America/Sao_Paulo",
    })
    expect(screen.getByText("Fuso horário: America/Sao_Paulo")).toBeInTheDocument()
  })

  it("sends no country when none is chosen and never sends a role", async () => {
    vi.mocked(register).mockResolvedValue({} as never)
    render(<RegisterForm />)

    fillValidForm()
    fireEvent.click(screen.getByRole("button", { name: "Criar conta" }))

    await waitFor(() => expect(register).toHaveBeenCalled())
    const input = vi.mocked(register).mock.calls[0]![0]
    expect(input.country_code).toBeNull()
    expect(input).not.toHaveProperty("platform_role")
  })

  it("lists countries by their Portuguese names", () => {
    render(<RegisterForm />)

    expect(screen.getByRole("option", { name: "Brasil" })).toHaveValue("BR")
    expect(screen.getByRole("option", { name: "Prefiro não informar" })).toHaveValue("")
  })

  it("shows each validation error next to its field", async () => {
    vi.mocked(register).mockRejectedValue(
      new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: {
          email: ["Este e-mail já está em uso."],
          nickname: ["Este apelido já está em uso."],
        },
      }),
    )
    render(<RegisterForm />)

    fillValidForm()
    fireEvent.click(screen.getByRole("button", { name: "Criar conta" }))

    expect(await screen.findByText("Este e-mail já está em uso.")).toBeInTheDocument()
    expect(screen.getByText("Este apelido já está em uso.")).toBeInTheDocument()
    expect(screen.getByLabelText("E-mail")).toHaveAttribute("aria-invalid", "true")
    expect(screen.getByLabelText("E-mail")).toHaveAccessibleDescription(
      "Este e-mail já está em uso.",
    )
    expect(navigateAfterSessionChange).not.toHaveBeenCalled()
  })
})
