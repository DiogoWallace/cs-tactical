/** Rotas do frontend usadas fora dos próprios componentes. */
export const LOGIN_PATH = "/login"
export const REGISTER_PATH = "/cadastro"
export const FORGOT_PASSWORD_PATH = "/esqueci-senha"

/** Para onde o usuário vai depois de entrar, quando não há `next`. */
export const HOME_PATH = "/painel"

/** Dados da conta: perfil, e-mail e senha. Dentro de /painel, já coberto pelo `proxy.ts`. */
export const ACCOUNT_PATH = "/painel/conta"

/**
 * Prefixos da área autenticada (grupo `(app)`). O `proxy.ts` usa a mesma lista no `matcher`;
 * uma rota nova da área logada precisa entrar nos dois lugares.
 */
export const PROTECTED_PATH_PREFIXES = ["/painel"] as const

export function isProtectedPath(pathname: string): boolean {
  return PROTECTED_PATH_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  )
}

// O navegador remove tab e quebras de linha ao montar a URL: "/<tab>/evil.example" viraria
// "//evil.example". Por isso qualquer caractere de controle invalida o caminho.
const CONTROL_CHARACTERS = /[\u0000-\u001f\u007f]/

/**
 * Aceita apenas caminhos internos ("/dashboard?tab=1"). Recusa URLs absolutas, "//host",
 * "/\host" e caracteres de controle, que o navegador trataria como outro domínio (open redirect).
 */
export function safeInternalPath(path: string | null | undefined): string | null {
  if (!path || !path.startsWith("/") || CONTROL_CHARACTERS.test(path)) return null
  if (path[1] === "/" || path[1] === "\\") return null
  return path
}

/** URL do login, com `next` apontando para onde o usuário estava (quando for um caminho interno). */
export function loginUrl(next?: string | null): string {
  const safeNext = safeInternalPath(next)
  if (!safeNext || safeNext === LOGIN_PATH || safeNext.startsWith(`${LOGIN_PATH}?`)) {
    return LOGIN_PATH
  }
  return `${LOGIN_PATH}?${new URLSearchParams({ next: safeNext })}`
}

/** Destino depois do login ou cadastro: o `next` recebido, se for seguro, ou o painel. */
export function afterLoginPath(next: string | null | undefined): string {
  const safeNext = safeInternalPath(next)
  if (!safeNext || safeNext === LOGIN_PATH || safeNext.startsWith(`${LOGIN_PATH}?`)) {
    return HOME_PATH
  }
  return safeNext
}
