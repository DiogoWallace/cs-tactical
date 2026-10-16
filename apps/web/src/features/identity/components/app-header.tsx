import Link from "next/link"

import { ThemeToggle } from "@/components/theme-toggle"
import { Skeleton } from "@/components/ui/skeleton"
import { ACCOUNT_PATH, HOME_PATH } from "@/lib/routes"

import { getCurrentUser } from "../api/get-current-user"
import { LogoutButton } from "./logout-button"

function Brand() {
  return (
    <Link href={HOME_PATH} className="font-semibold tracking-tight">
      CS <span className="text-primary">Tactical</span>
    </Link>
  )
}

/** Cabeçalho da área logada. Lê o usuário da sessão; sem sessão válida, redireciona ao login. */
export async function AppHeader() {
  const user = await getCurrentUser()
  const displayName = user.profile?.nickname ?? user.name

  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
        <div className="flex items-center gap-6">
          <Brand />
          <nav aria-label="Principal" className="flex gap-4 text-sm">
            <Link href={HOME_PATH} className="text-muted-foreground hover:text-foreground">
              Painel
            </Link>
            <Link href={ACCOUNT_PATH} className="text-muted-foreground hover:text-foreground">
              Conta
            </Link>
          </nav>
        </div>
        <div className="flex items-center gap-1 sm:gap-2">
          <span
            className="hidden max-w-40 truncate text-sm font-medium sm:inline"
            title={user.email}
          >
            {displayName}
          </span>
          <ThemeToggle />
          <LogoutButton />
        </div>
      </div>
    </header>
  )
}

export function AppHeaderSkeleton() {
  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
        <Brand />
        <Skeleton className="h-8 w-32" />
      </div>
    </header>
  )
}
