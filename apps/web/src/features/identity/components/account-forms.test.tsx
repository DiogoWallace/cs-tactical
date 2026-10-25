import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import {
  cancelEmailChange,
  changePassword,
  requestEmailChange,
  updateProfile,
} from "../api/account"
import type { CurrentUser } from "../types"
import { EmailChangeForm } from "./email-change-form"
import { PasswordChangeForm } from "./password-change-form"
import { ProfileForm } from "./profile-form"

vi.mock("../api/account", () => ({
  updateProfile: vi.fn(),
  changePassword: vi.fn(),
  requestEmailChange: vi.fn(),
  cancelEmailChange: vi.fn(),
}))

const refresh = vi.fn()
vi.mock("next/navigation", () => ({ useRouter: () => ({ refresh }) }))

const user: CurrentUser = {
  id: "u1",
  name: "Ana Souza",
  email: "ana@example.com",
  email_verified: true,
  pending_email: null,
  platform_role: "user",
  abilities: [],
  profile: { nickname: "AnaAWP", country_code: "BR", timezone: "America/Sao_Paulo", bio: null },
  steam: null,
  created_at: null,
}

function validation(errors: Record<string, string[]>) {
  return new ApiError(422, { message: "x", code: "validation_failed", errors })
}

function fill(label: string | RegExp, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } })
}

beforeEach(() => {
  vi.mocked(updateProfile).mockReset()
  vi.mocked(changePassword).mockReset()
  vi.mocked(requestEmailChange).mockReset()
  vi.mocked(cancelEmailChange).mockReset()
  refresh.mockReset()
})

describe("ProfileForm", () => {
  it("starts with the current values", () => {
    render(<ProfileForm user={user} />)

    expect(screen.getByLabelText("Nome")).toHaveValue("Ana Souza")
    expect(screen.getByLabelText("Apelido")).toHaveValue("AnaAWP")
    expect(screen.getByLabelText("País")).toHaveValue("BR")
    expect(screen.getByLabelText("Fuso horário")).toHaveValue("America/Sao_Paulo")
  })

  it("saves the profile and refreshes the server-rendered parts", async () => {
    vi.mocked(updateProfile).mockResolvedValue(user)
    render(<ProfileForm user={user} />)

    fill("Apelido", "AnaRifler")
    fill("País", "")
    fill("Bio (opcional)", "  Entry.  ")
    fireEvent.click(screen.getByRole("button", { name: "Salvar perfil" }))

    expect(await screen.findByRole("status")).toHaveTextContent("Perfil atualizado.")
    expect(updateProfile).toHaveBeenCalledWith({
      name: "Ana Souza",
      nickname: "AnaRifler",
      country_code: null,
      timezone: "America/Sao_Paulo",
      bio: "Entry.",
    })
    expect(refresh).toHaveBeenCalledOnce()
  })

  it("shows field errors", async () => {
    vi.mocked(updateProfile).mockImplementation(async () => {
      throw validation({ nickname: ["Este apelido já está em uso."] })
    })
    render(<ProfileForm user={user} />)

    fireEvent.click(screen.getByRole("button", { name: "Salvar perfil" }))

    expect(await screen.findByText("Este apelido já está em uso.")).toBeInTheDocument()
    expect(screen.getByLabelText("Apelido")).toHaveAttribute("aria-invalid", "true")
    expect(refresh).not.toHaveBeenCalled()
  })
})

describe("EmailChangeForm", () => {
  it("asks for the new address and the current password, then shows the pending change", async () => {
    vi.mocked(requestEmailChange).mockResolvedValue({ ...user, pending_email: "nova@example.com" })
    render(<EmailChangeForm user={user} />)

    fill("Novo e-mail", "nova@example.com")
    fill("Senha atual", "secret-1")
    fireEvent.click(screen.getByRole("button", { name: "Enviar link de confirmação" }))

    expect(await screen.findByRole("status")).toHaveTextContent(
      "Enviamos um link para nova@example.com",
    )
    expect(requestEmailChange).toHaveBeenCalledWith("nova@example.com", "secret-1")
    expect(screen.getByText("ana@example.com")).toBeInTheDocument()
  })

  it("cancels a pending change", async () => {
    vi.mocked(cancelEmailChange).mockResolvedValue(user)
    render(<EmailChangeForm user={{ ...user, pending_email: "nova@example.com" }} />)

    fireEvent.click(screen.getByRole("button", { name: "Cancelar troca" }))

    await waitFor(() => expect(screen.queryByText(/Enviamos um link/)).not.toBeInTheDocument())
  })

  it("shows a wrong current password next to the field", async () => {
    vi.mocked(requestEmailChange).mockImplementation(async () => {
      throw validation({ current_password: ["A senha atual está incorreta."] })
    })
    render(<EmailChangeForm user={user} />)

    fill("Novo e-mail", "nova@example.com")
    fill("Senha atual", "wrong")
    fireEvent.click(screen.getByRole("button", { name: "Enviar link de confirmação" }))

    expect(await screen.findByText("A senha atual está incorreta.")).toBeInTheDocument()
  })
})

describe("PasswordChangeForm", () => {
  function submit() {
    fill("Senha atual", "old-password-1")
    fill("Nova senha", "new-password-9")
    fill("Confirme a nova senha", "new-password-9")
    fireEvent.click(screen.getByRole("button", { name: "Alterar senha" }))
  }

  it("changes the password and explains that other sessions ended", async () => {
    vi.mocked(changePassword).mockResolvedValue()
    render(<PasswordChangeForm />)

    submit()

    expect(await screen.findByRole("status")).toHaveTextContent("outras sessões")
    expect(changePassword).toHaveBeenCalledWith({
      current_password: "old-password-1",
      password: "new-password-9",
      password_confirmation: "new-password-9",
    })
    expect(screen.getByLabelText("Nova senha")).toHaveValue("")
  })

  it("explains the rate limit", async () => {
    vi.mocked(changePassword).mockImplementation(async () => {
      throw new ApiError(429, { message: "Too Many Attempts.", code: "too_many_requests" })
    })
    render(<PasswordChangeForm />)

    submit()

    expect(await screen.findByRole("alert")).toHaveTextContent("Muitas tentativas")
  })
})
