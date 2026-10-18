import { useSyncExternalStore } from "react"

const subscribe = () => () => {}

/**
 * false no HTML do servidor e até o React assumir a página; true depois. Botões de escrita ficam
 * desabilitados até lá: um clique antes da hidratação não teria handler e se perderia em silêncio.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  )
}
