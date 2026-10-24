import { redirect } from "next/navigation"

import { ApiError, ApiUnavailableError } from "@/lib/api/errors"
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
    // API fora do ar ou limite de requisições atingido (429): mostra o formulário em vez de uma
    // página de erro; o envio exibirá o erro de conexão ou de limite.
    const rateLimited = error instanceof ApiError && error.status === 429
    if (!(error instanceof ApiUnavailableError) && !rateLimited) throw error
  }

  if (!authenticated) return null

  const next = (await searchParams)?.next
  redirect(afterLoginPath(typeof next === "string" ? next : null))
}
