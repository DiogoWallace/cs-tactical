import Link from "next/link"

import { Skeleton } from "@/components/ui/skeleton"
import { HOME_PATH } from "@/lib/routes"

import { getCurrentUser } from "../api/get-current-user"
import { AppNav } from "./app-nav"

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

  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
        <Brand />
        <AppNav displayName={user.profile?.nickname ?? user.name} email={user.email} />
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
