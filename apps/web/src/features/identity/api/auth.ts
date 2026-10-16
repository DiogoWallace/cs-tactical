import { apiClient } from "@/lib/api/client"

import type { CurrentUser, CurrentUserResponse, LoginInput, RegisterInput } from "../types"

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
