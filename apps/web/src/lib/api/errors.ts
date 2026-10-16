/**
 * Corpo de erro padronizado da API (docs/api/convencoes.md#erros).
 * `code` é estável; o texto exibido ao usuário é decidido no frontend a partir dele.
 */
export type ApiErrorBody = {
  message: string
  code: string
  errors?: Record<string, string[]>
}

export function isApiErrorBody(body: unknown): body is ApiErrorBody {
  return (
    typeof body === "object" &&
    body !== null &&
    typeof (body as { message?: unknown }).message === "string" &&
    typeof (body as { code?: unknown }).code === "string"
  )
}

/** Erro de uma chamada à API, com o status HTTP e o corpo JSON (quando houver). */
export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown, message?: string) {
    super(message ?? `API request failed with status ${status}`)
    this.name = "ApiError"
    this.status = status
    this.body = body
  }

  /** Código estável do erro (`unauthenticated`, `validation_failed`...), ou null se o corpo não seguir o padrão. */
  get code(): string | null {
    return isApiErrorBody(this.body) ? this.body.code : null
  }

  /** Erros de validação do Laravel (422): `{ message, code, errors: { campo: [mensagens] } }`. */
  get validationErrors(): Record<string, string[]> {
    if (this.status !== 422 || typeof this.body !== "object" || this.body === null) return {}
    const errors = (this.body as { errors?: unknown }).errors
    return typeof errors === "object" && errors !== null ? (errors as Record<string, string[]>) : {}
  }
}

/** A sessão não existe ou expirou (HTTP 401). */
export function isUnauthenticated(error: unknown): error is ApiError {
  return error instanceof ApiError && error.status === 401
}

/** A API não respondeu (rede, container fora do ar, timeout). */
export class ApiUnavailableError extends Error {
  constructor(cause?: unknown) {
    super("API is unavailable", { cause })
    this.name = "ApiUnavailableError"
  }
}

export async function parseBody(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}
