/** Rotas do frontend usadas fora dos próprios componentes. A página de login entra na etapa 1.3. */
export const LOGIN_PATH = "/login"

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
