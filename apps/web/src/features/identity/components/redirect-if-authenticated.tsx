import { redirect } from "next/navigation"

import { ApiUnavailableError } from "@/lib/api/errors"
import { HOME_PATH } from "@/lib/routes"

import { findCurrentUser } from "../api/get-current-user"

/**
 * Nas páginas de login e cadastro: quem já tem sessão válida vai direto ao painel.
 * A verificação é feita pela API (/me), não pela presença do cookie, para não criar um laço
 * login ↔ painel com um cookie de sessão expirado.
 */
export async function RedirectIfAuthenticated() {
  let authenticated = false
  try {
    authenticated = (await findCurrentUser()) !== null
  } catch (error) {
    // API fora do ar: mostra o formulário; o envio exibirá o erro de conexão.
    if (!(error instanceof ApiUnavailableError)) throw error
  }

  if (authenticated) redirect(HOME_PATH)
  return null
}
