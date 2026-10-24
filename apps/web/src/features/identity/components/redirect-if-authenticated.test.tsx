import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"

const findCurrentUser = vi.fn()
vi.mock("../api/get-current-user", () => ({ findCurrentUser: () => findCurrentUser() }))

const redirect = vi.fn((path: string) => {
  throw new Error(`NEXT_REDIRECT:${path}`)
})
vi.mock("next/navigation", () => ({ redirect: (path: string) => redirect(path) }))

const { RedirectIfAuthenticated } = await import("./redirect-if-authenticated")

describe("RedirectIfAuthenticated", () => {
  beforeEach(() => {
    findCurrentUser.mockReset()
    redirect.mockClear()
  })

  it("sends a logged-in user to the safe next path", async () => {
    findCurrentUser.mockResolvedValue({ id: 1 })

    await expect(
      RedirectIfAuthenticated({ searchParams: Promise.resolve({ next: "/painel/conta" }) }),
    ).rejects.toThrow("NEXT_REDIRECT:/painel/conta")
  })

  it("shows the form to visitors", async () => {
    findCurrentUser.mockResolvedValue(null)

    await expect(RedirectIfAuthenticated({})).resolves.toBeNull()
  })

  it("shows the form when the API is down or rate limited", async () => {
    findCurrentUser.mockRejectedValueOnce(new ApiUnavailableError())
    await expect(RedirectIfAuthenticated({})).resolves.toBeNull()

    findCurrentUser.mockRejectedValueOnce(
      new ApiError(429, { message: "Too Many Attempts.", code: "too_many_requests" }),
    )
    await expect(RedirectIfAuthenticated({})).resolves.toBeNull()
    expect(redirect).not.toHaveBeenCalled()
  })

  it("does not hide other API errors", async () => {
    findCurrentUser.mockRejectedValue(new ApiError(500, null))

    await expect(RedirectIfAuthenticated({})).rejects.toBeInstanceOf(ApiError)
  })
})
