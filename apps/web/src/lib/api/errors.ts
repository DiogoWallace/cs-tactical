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

  /** Erros de validação do Laravel (422): `{ message, errors: { campo: [mensagens] } }`. */
  get validationErrors(): Record<string, string[]> {
    if (this.status !== 422 || typeof this.body !== "object" || this.body === null) return {}
    const errors = (this.body as { errors?: unknown }).errors
    return typeof errors === "object" && errors !== null ? (errors as Record<string, string[]>) : {}
  }
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
