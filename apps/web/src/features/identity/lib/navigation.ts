/**
 * Navegação depois de entrar, criar conta ou sair: carrega a página do zero.
 *
 * A sessão mudou, então nada do estado do cliente (cache do router, componentes montados)
 * deve sobreviver. Uma navegação pelo router seguida de `router.refresh()` deixava a área
 * logada presa nos esqueletos de carregamento no Next 16.4.
 */
export function navigateAfterSessionChange(path: string): void {
  window.location.assign(path)
}

/**
 * Ida para o login de um provedor externo (Steam, etapa M2). Por navegação, não por formulário:
 * a CSP tem `form-action 'self'`, que bloquearia um envio para outro domínio.
 */
export function navigateToProvider(url: string): void {
  window.location.assign(url)
}
