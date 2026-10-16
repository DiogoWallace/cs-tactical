import Link from "next/link"
import { ActivityIcon, LogInIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { REGISTER_PATH } from "@/lib/routes"

export default function HomePage() {
  return (
    <section className="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center gap-6 px-4 py-16 sm:px-6">
      <p className="text-sm font-medium tracking-widest text-primary uppercase">
        Fase 1 · Identidade
      </p>
      <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
        Evolução individual e de equipe no Counter-Strike 2.
      </h1>
      <p className="max-w-xl text-lg text-muted-foreground">
        Academy para aprender com método. Teams para treinar, planejar e jogar juntos.
      </p>
      <div className="flex flex-wrap gap-3">
        <Button asChild>
          <Link href={REGISTER_PATH}>
            <LogInIcon />
            Criar conta
          </Link>
        </Button>
        <Button asChild variant="outline">
          <Link href="/status">
            <ActivityIcon />
            Status da plataforma
          </Link>
        </Button>
      </div>
    </section>
  )
}
