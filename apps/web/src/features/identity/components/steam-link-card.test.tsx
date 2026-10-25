import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { startSteamLink, unlinkSteam } from "../api/account"
import { navigateToProvider } from "../lib/navigation"
import type { CurrentUser } from "../types"
import { SteamLinkCard } from "./steam-link-card"

vi.mock("../api/account", () => ({ startSteamLink: vi.fn(), unlinkSteam: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateToProvider: vi.fn() }))

const user: CurrentUser = {
  id: "u1",
  name: "Ana Souza",
  email: "ana@example.com",
  email_verified: true,
  pending_email: null,
  platform_role: "user",
  abilities: [],
  profile: null,
  steam: null,
  created_at: null,
}

const linked: CurrentUser = {
  ...user,
  steam: { steam_id: "76561198000000001", linked_at: "2026-10-24T15:00:00Z" },
}

describe("SteamLinkCard", () => {
  beforeEach(() => {
    vi.mocked(startSteamLink).mockReset()
    vi.mocked(unlinkSteam).mockReset()
    vi.mocked(navigateToProvider).mockReset()
  })

  it("starts the link and goes to the Steam login by navigation", async () => {
    vi.mocked(startSteamLink).mockResolvedValue("https://steamcommunity.com/openid/login?x=1")
    render(<SteamLinkCard user={user} />)

    expect(screen.getByText(/Nenhuma conta Steam vinculada/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole("button", { name: "Vincular conta Steam" }))

    await waitFor(() =>
      expect(navigateToProvider).toHaveBeenCalledWith(
        "https://steamcommunity.com/openid/login?x=1",
      ),
    )
    expect(screen.getByRole("button", { name: /Vincular conta Steam/ })).toBeDisabled()
  })

  it("explains why the link could not start", async () => {
    vi.mocked(startSteamLink).mockRejectedValue(
      new ApiError(409, { message: "x", code: "steam_already_linked" }),
    )
    render(<SteamLinkCard user={user} />)

    fireEvent.click(screen.getByRole("button", { name: "Vincular conta Steam" }))

    expect(await screen.findByRole("alert")).toHaveTextContent("já tem uma conta Steam vinculada")
    expect(navigateToProvider).not.toHaveBeenCalled()
    expect(screen.getByRole("button", { name: "Vincular conta Steam" })).toBeEnabled()
  })

  it("shows the linked SteamID64 and date to the owner", () => {
    render(<SteamLinkCard user={linked} />)

    expect(screen.getByText("76561198000000001")).toBeInTheDocument()
    expect(screen.getByText("24 de outubro de 2026")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Vincular conta Steam" })).not.toBeInTheDocument()
  })

  it("asks for the password before unlinking", async () => {
    vi.mocked(unlinkSteam).mockResolvedValue()
    render(<SteamLinkCard user={linked} />)

    fireEvent.click(screen.getByRole("button", { name: "Desvincular" }))
    const confirm = screen.getByRole("form", { name: "Desvincular a conta Steam?" })
    const password = screen.getByLabelText("Senha atual")
    expect(password).toHaveFocus()

    fireEvent.change(password, { target: { value: "minha-senha-1" } })
    fireEvent.submit(confirm)

    await waitFor(() => expect(unlinkSteam).toHaveBeenCalledWith("minha-senha-1"))
    expect(await screen.findByRole("status")).toHaveTextContent("Conta Steam desvinculada.")
    expect(screen.getByRole("button", { name: "Vincular conta Steam" })).toBeInTheDocument()
    expect(screen.queryByText("76561198000000001")).not.toBeInTheDocument()
  })

  it("keeps the link and shows the error when the password is wrong", async () => {
    vi.mocked(unlinkSteam).mockRejectedValue(
      new ApiError(422, {
        message: "x",
        code: "validation_failed",
        errors: { current_password: ["A senha está incorreta."] },
      }),
    )
    render(<SteamLinkCard user={linked} />)

    fireEvent.click(screen.getByRole("button", { name: "Desvincular" }))
    fireEvent.submit(screen.getByRole("form", { name: "Desvincular a conta Steam?" }))

    expect(await screen.findByText("A senha está incorreta.")).toBeInTheDocument()
    expect(screen.getByLabelText("Senha atual")).toHaveAttribute("aria-invalid", "true")
    expect(screen.getByText("76561198000000001")).toBeInTheDocument()
  })

  it("cancels the unlink confirmation", () => {
    render(<SteamLinkCard user={linked} />)

    fireEvent.click(screen.getByRole("button", { name: "Desvincular" }))
    fireEvent.click(screen.getByRole("button", { name: "Cancelar" }))

    expect(screen.queryByLabelText("Senha atual")).not.toBeInTheDocument()
    expect(unlinkSteam).not.toHaveBeenCalled()
  })
})
