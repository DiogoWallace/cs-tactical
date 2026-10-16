import { apiClient } from "@/lib/api/client"

import type {
  ChangePasswordInput,
  CurrentUser,
  CurrentUserResponse,
  UpdateProfileInput,
} from "../types"

/* Conta do usuário autenticado (etapa 1.5). Escritas pelo apiClient, com CSRF. */

export async function updateProfile(input: UpdateProfileInput): Promise<CurrentUser> {
  const response = await apiClient.patch<CurrentUserResponse>("/api/v1/me/profile", input)
  return response.data
}

/** A sessão atual continua; as outras são encerradas pela API. */
export async function changePassword(input: ChangePasswordInput): Promise<void> {
  await apiClient.put<null>("/api/v1/me/password", input)
}

/** Guarda o novo e-mail como pendente e envia o link de confirmação a ele. */
export async function requestEmailChange(
  email: string,
  currentPassword: string,
): Promise<CurrentUser> {
  const response = await apiClient.post<CurrentUserResponse>("/api/v1/me/email", {
    email,
    current_password: currentPassword,
  })
  return response.data
}

export async function cancelEmailChange(): Promise<CurrentUser> {
  const response = await apiClient.delete<CurrentUserResponse>("/api/v1/me/email")
  return response.data
}

/**
 * Conclui a troca com o link recebido no novo endereço. `apiPath` é o caminho assinado da API,
 * com a query intacta. Sem sessão, o apiClient leva ao login e volta.
 */
export async function confirmEmailChange(apiPath: `/${string}`): Promise<CurrentUser> {
  const response = await apiClient.get<CurrentUserResponse>(apiPath)
  return response.data
}
