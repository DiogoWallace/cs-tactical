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
