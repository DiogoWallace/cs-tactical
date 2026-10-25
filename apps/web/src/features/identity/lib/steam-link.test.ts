import { describe, expect, it } from "vitest"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"

import { readSteamReturn, steamErrorMessage } from "./steam-link"

describe("readSteamReturn", () => {
  it("keeps only the state and the openid fields, without the prefix", () => {
    expect(
      readSteamReturn(
        "?state=s1&openid.mode=id_res&openid.return_to=http%3A%2F%2Fx%3Fstate%3Ds1&other=1",
      ),
    ).toEqual({ state: "s1", openid: { mode: "id_res", return_to: "http://x?state=s1" } })
  })

  it("has no state when the page is opened directly", () => {
    expect(readSteamReturn("")).toEqual({ state: null, openid: {} })
  })
})

describe("steamErrorMessage", () => {
  it("falls back to the generic messages", () => {
    expect(steamErrorMessage(new ApiUnavailableError())).toMatch(/Não foi possível conectar/)
    expect(
      steamErrorMessage(new ApiError(429, { message: "x", code: "too_many_requests" })),
    ).toMatch(/Muitas tentativas/)
  })
})
