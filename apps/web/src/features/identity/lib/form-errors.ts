import { ApiError, ApiUnavailableError } from "@/lib/api/errors"

/** Erros prontos para exibir num formulário: um por campo e um geral. */
export type FormErrors = {
  fields: Record<string, string>
  form: string | null
}

export const NO_ERRORS: FormErrors = { fields: {}, form: null }

/**
 * Converte o erro de uma chamada à API em mensagens para o usuário.
 * Erros de validação (422) já vêm em português da API, por campo. Os demais usam o `code`
 * estável (docs/api/convencoes.md#erros), nunca a `message` técnica.
 */
export function toFormErrors(error: unknown): FormErrors {
  if (error instanceof ApiUnavailableError) {
    return {
      fields: {},
      form: "Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.",
    }
  }

  if (!(error instanceof ApiError)) {
    return { fields: {}, form: "Algo deu errado. Tente novamente." }
  }

  if (error.status === 422) {
    const fields = Object.fromEntries(
      Object.entries(error.validationErrors)
        .filter(([, messages]) => messages.length > 0)
        .map(([field, messages]) => [field, messages[0]!]),
    )
    return { fields, form: null }
  }

  switch (error.code) {
    case "too_many_requests":
      return { fields: {}, form: "Muitas tentativas. Aguarde um minuto e tente novamente." }
    case "csrf_token_mismatch":
      return { fields: {}, form: "Sua sessão expirou. Recarregue a página e tente novamente." }
    case "frontend_session_required":
      return {
        fields: {},
        form: "Não foi possível iniciar a sessão. Verifique se os cookies estão habilitados.",
      }
    default:
      return { fields: {}, form: "Algo deu errado. Tente novamente." }
  }
}
