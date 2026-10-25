import { ApiError } from "@/lib/api/errors"

import { toFormErrors } from "./form-errors"

/** Prefixo dos campos OpenID que a Steam acrescenta à URL de volta. */
const OPENID_PREFIX = "openid."

/**
 * O que chegou na URL de volta da Steam: o `state` (posto pela API na ida) e os campos
 * `openid.*`, sem o prefixo, como a API espera. Nada daqui é exibido na tela.
 */
export function readSteamReturn(search: string): {
  state: string | null
  openid: Record<string, string>
} {
  const params = new URLSearchParams(search)
  const openid: Record<string, string> = {}
  for (const [key, value] of params) {
    if (key.startsWith(OPENID_PREFIX)) openid[key.slice(OPENID_PREFIX.length)] = value
  }
  return { state: params.get("state"), openid }
}

const STEAM_MESSAGES: Record<string, string> = {
  steam_state_invalid:
    "Este vínculo expirou ou foi iniciado em outra sessão. Comece de novo pela página da conta.",
  steam_cancelled: "Você cancelou na Steam. Nenhuma conta foi vinculada.",
  steam_response_invalid: "A resposta da Steam não pôde ser aceita. Tente de novo.",
  steam_replay: "Esta resposta da Steam já foi usada ou expirou. Comece de novo.",
  steam_not_verified: "A Steam não confirmou o login. Tente de novo.",
  steam_unavailable: "Não foi possível falar com a Steam agora. Tente de novo em alguns minutos.",
  steam_already_linked: "Sua conta já tem uma conta Steam vinculada.",
  steam_account_in_use: "Esta conta Steam já está vinculada a outra conta do CS Tactical.",
}

/** Mensagem para o usuário a partir do `code` estável da API (nunca da `message` técnica). */
export function steamErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const message = error.code ? STEAM_MESSAGES[error.code] : undefined
    if (message) return message
    // Resposta malformada (campo desconhecido, tipo errado): o mesmo que uma resposta inválida.
    if (error.status === 422) return STEAM_MESSAGES.steam_response_invalid!
  }
  return toFormErrors(error).form ?? "Algo deu errado. Tente novamente."
}
