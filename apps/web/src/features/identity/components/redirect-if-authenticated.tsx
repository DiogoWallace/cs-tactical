import { redirect } from "next/navigation"

import { ApiUnavailableError } from "@/lib/api/errors"
import { afterLoginPath } from "@/lib/routes"

import { findCurrentUser } from "../api/get-current-user"

type Props = {
  /** `searchParams` da página: com `?next=`, quem já está logado vai para lá (só caminhos internos). */
  searchParams?: Promise<Record<string, string | string[] | undefined>>
}

/**
 * Nas páginas de login, cadastro e "esqueci minha senha": quem já tem sessão válida segue
 * para o `next` ou para o painel. A verificação é feita pela API (/me), não pela presença do
 * cookie, para não criar um laço login ↔ painel com um cookie de sessão expirado.
 */
export async function RedirectIfAuthenticated({ searchParams }: Props) {
  let authenticated = false
  try {
    authenticated = (await findCurrentUser()) !== null
  } catch (error) {
    // API fora do ar: mostra o formulário; o envio exibirá o erro de conexão.
    if (!(error instanceof ApiUnavailableError)) throw error
  }

  if (!authenticated) return null

  const next = (await searchParams)?.next
  redirect(afterLoginPath(typeof next === "string" ? next : null))
}
