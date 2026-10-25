import { render, screen } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import { completeSteamLink } from "../api/account"
import { SteamLinkReturn } from "./steam-link-return"

vi.mock("../api/account", () => ({ completeSteamLink: vi.fn() }))

const RETURN =
  "/painel/conta/steam?state=abc123" +
  "&openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0&openid.mode=id_res" +
  "&openid.claimed_id=https%3A%2F%2Fsteamcommunity.com%2Fopenid%2Fid%2F76561198000000001" +
  "&openid.sig=%3Cscript%3Ealert(1)%3C%2Fscript%3E"

describe("SteamLinkReturn", () => {
  beforeEach(() => {
    vi.mocked(completeSteamLink).mockReset()
    window.history.replaceState(null, "", RETURN)
  })

  afterEach(() => {
    window.history.replaceState(null, "", "/")
  })

  it("sends the state and the openid fields, then clears them from the address bar", async () => {
    vi.mocked(completeSteamLink).mockResolvedValue({
      steam_id: "76561198000000001",
      linked_at: "2026-10-24T15:00:00Z",
    })
    render(<SteamLinkReturn />)

    expect(window.location.search).toBe("")
    expect(window.location.pathname).toBe("/painel/conta/steam")
    expect(completeSteamLink).toHaveBeenCalledOnce()
    expect(completeSteamLink).toHaveBeenCalledWith("abc123", {
      ns: "http://specs.openid.net/auth/2.0",
      mode: "id_res",
      claimed_id: "https://steamcommunity.com/openid/id/76561198000000001",
      sig: "<script>alert(1)</script>",
    })
    expect(screen.getByRole("heading", { name: "Conta Steam" })).toHaveFocus()
    await vi.waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("Conta Steam vinculada."),
    )
    expect(screen.getByRole("link", { name: "Voltar para a conta" })).toHaveAttribute(
      "href",
      "/painel/conta",
    )
  })

  it.each([
    ["steam_cancelled", 422, "Você cancelou na Steam"],
    ["steam_state_invalid", 422, "expirou ou foi iniciado em outra sessão"],
    ["steam_response_invalid", 422, "não pôde ser aceita"],
    ["validation_failed", 422, "não pôde ser aceita"],
    ["steam_replay", 422, "já foi usada ou expirou"],
    ["steam_not_verified", 422, "não confirmou"],
    ["steam_unavailable", 503, "Não foi possível falar com a Steam"],
    ["steam_already_linked", 409, "já tem uma conta Steam vinculada"],
    ["steam_account_in_use", 409, "vinculada a outra conta"],
  ])("explains %s without showing what Steam sent", async (code, status, text) => {
    vi.mocked(completeSteamLink).mockRejectedValue(new ApiError(status, { message: "x", code }))
    render(<SteamLinkReturn />)

    const alert = await screen.findByRole("alert")
    expect(alert).toHaveTextContent(text)
    expect(document.body).not.toHaveTextContent("76561198000000001")
    expect(document.body).not.toHaveTextContent("<script>")
    expect(screen.getByRole("link", { name: "Voltar para a conta" })).toBeInTheDocument()
  })

  it("stays quiet while the client sends the user to the login", async () => {
    vi.mocked(completeSteamLink).mockRejectedValue(
      new ApiError(401, { message: "Unauthenticated.", code: "unauthenticated" }),
    )
    render(<SteamLinkReturn />)

    await vi.waitFor(() => expect(completeSteamLink).toHaveBeenCalled())
    expect(screen.queryByRole("alert")).not.toBeInTheDocument()
    expect(screen.getByRole("status")).toHaveTextContent("Confirmando o vínculo")
  })
})
