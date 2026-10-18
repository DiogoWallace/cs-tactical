import { Suspense } from "react"
import Link from "next/link"

import { SiteNavLink } from "@/components/site-nav-link"
import { ThemeToggle } from "@/components/theme-toggle"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { ACADEMY_PATH, HOME_PATH, LOGIN_PATH, REGISTER_PATH } from "@/lib/routes"

function Brand() {
  return (
    <Link href="/" className="font-semibold tracking-tight">
      CS <span className="text-primary">Tactical</span>
    </Link>
  )
}

/**
 * Cabeçalho das páginas públicas (início, status e Academy) para quem não está logado.
 * Quem decide entre este e o da área logada é o SessionHeader (features/identity).
 *
 * `sessionUnknown`: há cookie de sessão, mas a API não respondeu. Sem como afirmar que é um
 * visitante, "Entrar" e "Criar conta" dão lugar ao link do painel (que trata a sessão).
 */
export function SiteHeader({ sessionUnknown = false }: { sessionUnknown?: boolean }) {
  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex items-center gap-6">
          <Brand />
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
          {sessionUnknown ? (
            <Button asChild variant="ghost" size="sm">
              <Link href={HOME_PATH}>Painel</Link>
            </Button>
          ) : (
            <>
              <Button asChild variant="ghost" size="sm">
                <Link href={LOGIN_PATH}>Entrar</Link>
              </Button>
              {/* No celular a largura é da navegação; o cadastro continua no início e no login. */}
              <Button asChild size="sm" className="hidden sm:inline-flex">
                <Link href={REGISTER_PATH}>Criar conta</Link>
              </Button>
            </>
          )}
        </div>
      </div>
    </header>
  )
}

/**
 * Enquanto a sessão é resolvida: só a marca e um espaço reservado. Nenhum link de visitante ou de
 * usuário logado aparece antes da hora (nada de "Entrar" piscando para quem está logado).
 */
export function SiteHeaderSkeleton() {
  return (
    <header className="border-b" aria-busy="true">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
        <Brand />
        <Skeleton className="h-8 w-40" />
      </div>
    </header>
  )
}
