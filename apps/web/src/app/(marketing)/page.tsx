import { Suspense } from "react"
import Link from "next/link"
import { ActivityIcon, GraduationCapIcon, LayoutDashboardIcon, LogInIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { getSessionState } from "@/features/identity/api/get-session-state"
import { ACADEMY_PATH, HOME_PATH, REGISTER_PATH } from "@/lib/routes"

export default function HomePage() {
  return (
    <section className="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center gap-6 px-4 py-16 sm:px-6">
      <p className="text-sm font-medium tracking-widest text-primary uppercase">Fase 2 · Academy</p>
      <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
        Evolução individual e de equipe no Counter-Strike 2.
      </h1>
      <p className="max-w-xl text-lg text-muted-foreground">
        Academy para aprender com método. Teams para treinar, planejar e jogar juntos.
      </p>
      <div className="flex flex-wrap gap-3">
        {/* A ação principal depende da sessão; o espaço reservado evita mostrar a errada. */}
        <Suspense fallback={<Skeleton className="h-9 w-36" />}>
          <PrimaryAction />
        </Suspense>
        <Button asChild variant="outline">
          <Link href={ACADEMY_PATH}>
            <GraduationCapIcon />
            Explorar a Academy
          </Link>
        </Button>
        <Button asChild variant="ghost">
          <Link href="/status">
            <ActivityIcon />
            Status da plataforma
          </Link>
        </Button>
      </div>
    </section>
  )
}

/**
 * Logado: volta ao painel. Visitante: o cadastro. Com cookie e API sem resposta, também o painel
 * (como o cabeçalho): não dá para afirmar que é um visitante.
 */
async function PrimaryAction() {
  const session = await getSessionState()

  if (session.kind !== "visitor") {
    return (
      <Button asChild>
        <Link href={HOME_PATH}>
          <LayoutDashboardIcon />
          Ir para o painel
        </Link>
      </Button>
    )
  }
  return (
    <Button asChild>
      <Link href={REGISTER_PATH}>
        <LogInIcon />
        Criar conta
      </Link>
    </Button>
  )
}
