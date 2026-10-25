import { apiClient, apiRequest } from "@/lib/api/client"

import type {
  ChangePasswordInput,
  CurrentUser,
  CurrentUserResponse,
  SteamAccount,
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

/* Vínculo da conta Steam (etapa M2, docs/training/mvp-m2-vinculo-steam.md). */

/** Inicia o vínculo: a URL de login da Steam, com o `state` guardado na sessão pela API. */
export async function startSteamLink(): Promise<string> {
  const response = await apiClient.post<{ redirect_url: string }>("/api/v1/me/steam/link")
  return response.redirect_url
}

/** Conclui com o que a Steam devolveu na volta (campos `openid.*` sem o prefixo). */
export async function completeSteamLink(
  state: string | null,
  openid: Record<string, string>,
): Promise<SteamAccount> {
  const response = await apiClient.post<{ steam: SteamAccount }>("/api/v1/me/steam/link/complete", {
    state,
    openid,
  })
  return response.steam
}

/** Desfaz o vínculo; exige a senha atual. */
export async function unlinkSteam(currentPassword: string): Promise<void> {
  await apiRequest<null>("/api/v1/me/steam", {
    method: "DELETE",
    body: { current_password: currentPassword },
  })
}
