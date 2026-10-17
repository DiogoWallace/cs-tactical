import type { Metadata } from "next"
import { Suspense } from "react"

import { Skeleton } from "@/components/ui/skeleton"
import { getCurrentUser } from "@/features/identity/api/get-current-user"
import { getMyEnrollments } from "@/features/learning/api/progress"
import { MyProgress } from "@/features/learning/components/my-progress"

export const metadata: Metadata = {
  title: "Meu progresso",
}

/** "Meu progresso" (etapa 2.4): matrículas, progresso e histórico do jogador da sessão. */
export default function MyProgressPage() {
  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-semibold tracking-tight">Meu progresso</h1>
        <p className="text-muted-foreground">Seus cursos da Academy e de onde continuar.</p>
      </header>
      {/* Lê a sessão: fica atrás de Suspense (Cache Components). */}
      <Suspense fallback={<MyProgressSkeleton />}>
        <MyProgressContent />
      </Suspense>
    </div>
  )
}

async function MyProgressContent() {
  // Sem sessão válida, getCurrentUser leva ao login (voltando para cá).
  const [user, result] = await Promise.all([getCurrentUser(), getMyEnrollments()])

  if (result.kind !== "player") {
    return (
      <p role="alert" className="text-muted-foreground">
        Não foi possível carregar seu progresso agora. Tente de novo em instantes.
      </p>
    )
  }
  return (
    <MyProgress
      enrollments={result.enrollments}
      timeZone={user.profile?.timezone ?? "America/Sao_Paulo"}
    />
  )
}

function MyProgressSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Carregando seu progresso"
      className="grid gap-4 md:grid-cols-2"
    >
      <Skeleton className="h-48 rounded-xl" />
      <Skeleton className="h-48 rounded-xl" />
    </div>
  )
}
