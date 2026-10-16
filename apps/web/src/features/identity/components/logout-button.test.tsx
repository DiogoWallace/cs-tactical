import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiUnavailableError } from "@/lib/api/errors"

import { logout } from "../api/auth"
import { navigateAfterSessionChange } from "../lib/navigation"
import { LogoutButton } from "./logout-button"

vi.mock("../api/auth", () => ({ logout: vi.fn() }))
vi.mock("../lib/navigation", () => ({ navigateAfterSessionChange: vi.fn() }))

describe("LogoutButton", () => {
  beforeEach(() => {
    vi.mocked(logout).mockReset()
    vi.mocked(navigateAfterSessionChange).mockReset()
  })

  it("logs out and goes to the login", async () => {
    vi.mocked(logout).mockResolvedValue()
    render(<LogoutButton />)

    fireEvent.click(screen.getByRole("button", { name: "Sair" }))

    await waitFor(() => expect(navigateAfterSessionChange).toHaveBeenCalledWith("/login"))
    expect(logout).toHaveBeenCalledOnce()
  })

  it("still leaves the authenticated area when the API fails", async () => {
    vi.mocked(logout).mockRejectedValue(new ApiUnavailableError())
    render(<LogoutButton />)

    fireEvent.click(screen.getByRole("button", { name: "Sair" }))

    await waitFor(() => expect(navigateAfterSessionChange).toHaveBeenCalledWith("/login"))
  })
})
