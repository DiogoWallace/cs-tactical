import { apiClient } from "@/lib/api/client"

import type {
  CurrentUser,
  CurrentUserResponse,
  LoginInput,
  RegisterInput,
  ResetPasswordInput,
} from "../types"

/*
 * Chamadas de autenticação feitas no navegador (escritas vão sempre pelo apiClient,
 * com CSRF; ver docs/architecture/autenticacao.md). Um 401 aqui é esperado e tratado
 * pelo formulário, então não dispara o redirecionamento automático ao login.
 */

export async function login(input: LoginInput): Promise<CurrentUser> {
  const response = await apiClient.post<CurrentUserResponse>("/api/v1/auth/login", input, {
    redirectOnUnauthenticated: false,
  })
  return response.data
}

export async function register(input: RegisterInput): Promise<CurrentUser> {
  const response = await apiClient.post<CurrentUserResponse>("/api/v1/auth/register", input, {
    redirectOnUnauthenticated: false,
  })
  return response.data
}

export async function logout(): Promise<void> {
  await apiClient.post<null>("/api/v1/auth/logout", undefined, {
    redirectOnUnauthenticated: false,
  })
}

/** Sempre a mesma resposta, exista ou não conta com o e-mail (a API não revela). */
export async function requestPasswordReset(email: string): Promise<string> {
  const response = await apiClient.post<{ message: string }>(
    "/api/v1/auth/forgot-password",
    { email },
    { redirectOnUnauthenticated: false },
  )
  return response.message
}

export async function resetPassword(input: ResetPasswordInput): Promise<string> {
  const response = await apiClient.post<{ message: string }>("/api/v1/auth/reset-password", input, {
    redirectOnUnauthenticated: false,
  })
  return response.message
}

/**
 * Confirma o e-mail com o link recebido. `apiPath` é o caminho assinado da API, com a query
 * intacta (a assinatura cobre caminho e query). Sem sessão, o apiClient leva ao login e volta.
 */
export async function verifyEmail(apiPath: `/${string}`): Promise<void> {
  await apiClient.get<null>(apiPath)
}

/** Reenvia o link de confirmação (202). Se o e-mail já estiver confirmado, a API não envia nada (204). */
export async function resendVerification(): Promise<void> {
  await apiClient.post<null>("/api/v1/auth/email/verification-notification")
}
