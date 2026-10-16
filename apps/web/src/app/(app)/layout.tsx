import { Suspense } from "react"

import { AppHeader, AppHeaderSkeleton } from "@/features/identity/components/app-header"

/**
 * Área autenticada. Três camadas de proteção (docs/architecture/autenticacao.md):
 * 1. `proxy.ts` redireciona ao login quem não tem o cookie de sessão;
 * 2. o cabeçalho e as páginas leem /api/v1/me e redirecionam ao login em 401;
 * 3. a autorização de cada dado é sempre da API.
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Suspense fallback={<AppHeaderSkeleton />}>
        <AppHeader />
      </Suspense>
      <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col px-4 py-8 sm:px-6">
        {children}
      </main>
    </>
  )
}
