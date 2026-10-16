import type { Metadata } from "next"
import { Suspense } from "react"

import { BrowserCheck } from "@/features/system/components/browser-check"
import {
  HealthOverview,
  HealthOverviewSkeleton,
} from "@/features/system/components/health-overview"

export const metadata: Metadata = {
  title: "Status",
}

export default function StatusPage() {
  return (
    <section className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-12 sm:px-6">
      <header className="flex flex-col gap-2">
        <h1 className="text-3xl font-semibold tracking-tight">Status da plataforma</h1>
        <p className="text-muted-foreground">
          Comunicação entre o frontend, a API e os serviços de infraestrutura.
        </p>
      </header>

      {/* Lido no servidor a cada requisição: fica atrás de Suspense (Cache Components). */}
      <Suspense fallback={<HealthOverviewSkeleton />}>
        <HealthOverview />
      </Suspense>

      <BrowserCheck />
    </section>
  )
}
