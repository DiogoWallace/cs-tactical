import Link from "next/link"

import { ThemeToggle } from "@/components/theme-toggle"
import { Button } from "@/components/ui/button"
import { LOGIN_PATH, REGISTER_PATH } from "@/lib/routes"

export function SiteHeader() {
  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
        <Link href="/" className="font-semibold tracking-tight">
          CS <span className="text-primary">Tactical</span>
        </Link>
        <div className="flex items-center gap-2">
          <ThemeToggle />
          <Button asChild variant="ghost" size="sm">
            <Link href={LOGIN_PATH}>Entrar</Link>
          </Button>
          <Button asChild size="sm">
            <Link href={REGISTER_PATH}>Criar conta</Link>
          </Button>
        </div>
      </div>
    </header>
  )
}
