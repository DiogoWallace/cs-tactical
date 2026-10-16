/** Usuário autenticado, como devolvido por GET /api/v1/me, login e cadastro (UserResource). */
export type CurrentUser = {
  id: string
  name: string
  email: string
  email_verified: boolean
  platform_role: "user" | "editor" | "admin"
  profile: {
    nickname: string
    country_code: string | null
    timezone: string
    bio: string | null
  } | null
  created_at: string | null
}

export type CurrentUserResponse = { data: CurrentUser }

export type LoginInput = {
  email: string
  password: string
  remember: boolean
}

export type RegisterInput = {
  name: string
  email: string
  password: string
  password_confirmation: string
  nickname: string
  country_code: string | null
  timezone: string | null
}

export type ResetPasswordInput = {
  token: string
  email: string
  password: string
  password_confirmation: string
}
