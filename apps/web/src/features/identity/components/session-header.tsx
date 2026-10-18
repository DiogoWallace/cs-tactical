import { SiteHeader } from "@/components/site-header"

import { getSessionState, type SessionState } from "../api/get-session-state"
import { AppHeaderBar } from "./app-header"

/**
 * Cabeçalho das páginas públicas, conforme a sessão (docs/architecture/autenticacao.md).
 * Lê cookies e a API: fica atrás de <Suspense> no layout (Cache Components), e o shell estático
 * só leva o esqueleto, nunca dados da conta.
 */
export async function SessionHeader() {
  return <SessionHeaderView session={await getSessionState()} />
}

export function SessionHeaderView({ session }: { session: SessionState }) {
  switch (session.kind) {
    case "user":
      return <AppHeaderBar user={session.user} />
    case "unknown":
      return <SiteHeader sessionUnknown />
    case "visitor":
      return <SiteHeader />
  }
}
