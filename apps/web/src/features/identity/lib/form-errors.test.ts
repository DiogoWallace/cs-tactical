import { describe, expect, it } from "vitest"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"

import { toFormErrors } from "./form-errors"

describe("toFormErrors", () => {
  it("keeps the first message of each field from a 422", () => {
    const error = new ApiError(422, {
      message: "x",
      code: "validation_failed",
      errors: { email: ["Informe um e-mail válido."], password: ["Curta.", "Não confere."] },
    })

    expect(toFormErrors(error)).toEqual({
      fields: { email: "Informe um e-mail válido.", password: "Curta." },
      form: null,
    })
  })

  it.each([
    ["too_many_requests", 429, "Muitas tentativas"],
    ["csrf_token_mismatch", 419, "Sua sessão expirou"],
    ["frontend_session_required", 403, "cookies"],
    ["server_error", 500, "Algo deu errado"],
  ])("explains %s without technical details", (code, status, expected) => {
    const result = toFormErrors(new ApiError(status, { message: "Technical message", code }))

    expect(result.fields).toEqual({})
    expect(result.form).toContain(expected)
    expect(result.form).not.toContain("Technical message")
  })

  it("explains a connection failure", () => {
    expect(toFormErrors(new ApiUnavailableError()).form).toContain("Não foi possível conectar")
  })
})
