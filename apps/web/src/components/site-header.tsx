import { Suspense } from "react"
import Link from "next/link"

import { SiteNavLink } from "@/components/site-nav-link"
import { ThemeToggle } from "@/components/theme-toggle"
import { Button } from "@/components/ui/button"
import { ACADEMY_PATH, LOGIN_PATH, REGISTER_PATH } from "@/lib/routes"

/** Cabeçalho das páginas públicas (início, status e Academy). */
export function SiteHeader() {
  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex items-center gap-6">
          <Link href="/" className="font-semibold tracking-tight">
            CS <span className="text-primary">Tactical</span>
          </Link>
          <nav aria-label="Principal" className="text-sm">
            {/* `usePathname` suspende nas rotas com parâmetros (cursos e aulas): o fallback é o
                mesmo link, sem a marcação da seção atual. */}
            <Suspense
              fallback={
                <Link href={ACADEMY_PATH} className="text-muted-foreground hover:text-foreground">
                  Academy
                </Link>
              }
            >
              <SiteNavLink href={ACADEMY_PATH}>Academy</SiteNavLink>
            </Suspense>
          </nav>
        </div>
        <div className="flex items-center gap-2">
          <ThemeToggle />
          <Button asChild variant="ghost" size="sm">
            <Link href={LOGIN_PATH}>Entrar</Link>
          </Button>
          {/* No celular a largura é da navegação; o cadastro continua no início e no login. */}
          <Button asChild size="sm" className="hidden sm:inline-flex">
            <Link href={REGISTER_PATH}>Criar conta</Link>
          </Button>
        </div>
      </div>
    </header>
  )
}
