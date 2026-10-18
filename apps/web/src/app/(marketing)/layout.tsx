import { Suspense } from "react"

import { SiteHeaderSkeleton } from "@/components/site-header"
import { SessionHeader } from "@/features/identity/components/session-header"

/**
 * Páginas públicas: início, status e Academy. O conteúdo é o mesmo para todos; só o cabeçalho
 * muda com a sessão (visitante: Entrar e Criar conta; logado: a navegação da área logada).
 */
export default function MarketingLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Suspense fallback={<SiteHeaderSkeleton />}>
        <SessionHeader />
      </Suspense>
      <main className="flex flex-1 flex-col">{children}</main>
    </>
  )
}
