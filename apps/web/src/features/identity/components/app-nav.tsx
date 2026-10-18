"use client"

import { useState } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { MenuIcon, XIcon } from "lucide-react"
import { Dialog } from "radix-ui"

import { ThemeToggle } from "@/components/theme-toggle"
import { Button } from "@/components/ui/button"
import { ACADEMY_PATH, ACCOUNT_PATH, HOME_PATH, MY_PROGRESS_PATH } from "@/lib/routes"
import { cn } from "@/lib/utils"

import { LogoutButton } from "./logout-button"

const LINKS = [
  { href: HOME_PATH, label: "Painel" },
  { href: ACADEMY_PATH, label: "Academy" },
  { href: MY_PROGRESS_PATH, label: "Meu progresso" },
  { href: ACCOUNT_PATH, label: "Conta" },
] as const

/** A Academy é uma seção: cursos e aulas também marcam o link. As demais, só a própria página. */
function isActive(pathname: string, href: string): boolean {
  return href === ACADEMY_PATH
    ? pathname === href || pathname.startsWith(`${href}/`)
    : pathname === href
}

type Props = { displayName: string; email: string }

/**
 * Navegação da área logada. A partir de `sm`, links e ações no cabeçalho; no celular, um
 * painel lateral (Dialog do Radix: foco preso enquanto aberto, Esc fecha, foco volta ao botão).
 */
export function AppNav({ displayName, email }: Props) {
  const pathname = usePathname()
  const [open, setOpen] = useState(false)

  const links = (onNavigate?: () => void) =>
    LINKS.map(({ href, label }) => {
      const active = isActive(pathname, href)
      return (
        <Link
          key={href}
          href={href}
          onClick={onNavigate}
          aria-current={active ? "page" : undefined}
          className={cn(
            "text-muted-foreground hover:text-foreground",
            active && "font-medium text-foreground",
          )}
        >
          {label}
        </Link>
      )
    })

  return (
    <>
      <nav aria-label="Principal" className="hidden gap-4 text-sm sm:flex">
        {links()}
      </nav>

      <div className="hidden items-center gap-2 sm:flex">
        <span className="max-w-40 truncate text-sm font-medium" title={email}>
          {displayName}
        </span>
        <ThemeToggle />
        <LogoutButton />
      </div>

      <Dialog.Root open={open} onOpenChange={setOpen}>
        <Dialog.Trigger asChild>
          <Button variant="ghost" size="icon" className="sm:hidden" aria-label="Abrir menu">
            <MenuIcon />
          </Button>
        </Dialog.Trigger>
        <Dialog.Portal>
          <Dialog.Overlay className="fixed inset-0 z-40 bg-black/50" />
          <Dialog.Content className="fixed inset-y-0 right-0 z-50 flex w-72 max-w-[85vw] flex-col gap-6 border-l bg-background p-6 shadow-lg focus:outline-none">
            <div className="flex items-center justify-between">
              <Dialog.Title className="text-base font-semibold">Menu</Dialog.Title>
              <Dialog.Close asChild>
                <Button variant="ghost" size="icon" aria-label="Fechar menu">
                  <XIcon />
                </Button>
              </Dialog.Close>
            </div>
            <Dialog.Description className="text-sm text-muted-foreground">
              Conectado como <span className="font-medium text-foreground">{displayName}</span>
              <span className="block truncate">{email}</span>
            </Dialog.Description>
            <nav aria-label="Principal" className="flex flex-col gap-3 text-base">
              {links(() => setOpen(false))}
            </nav>
            <div className="mt-auto flex items-center justify-between">
              <ThemeToggle />
              <LogoutButton />
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </>
  )
}
