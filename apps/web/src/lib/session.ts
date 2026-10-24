/**
 * Nome do cookie de sessão emitido pelo Laravel (SESSION_COOKIE em apps/api/.env).
 * O frontend só verifica se ele existe; o conteúdo é criptografado e HTTP-only.
 */
export const SESSION_COOKIE = "cs_tactical_session"

/** Prefixo do cookie "manter conectado" do Laravel (`remember_web_<hash>`, 30 dias). */
export const REMEMBER_COOKIE_PREFIX = "remember_web_"

/** Header em que o `proxy.ts` repassa o caminho atual aos Server Components. */
export const PATHNAME_HEADER = "x-pathname"
