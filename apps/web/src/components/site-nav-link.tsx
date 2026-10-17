"use client"

import Link from "next/link"
import { usePathname } from "next/navigation"

import { cn } from "@/lib/utils"

/** Link de seção do cabeçalho público: marcado (`aria-current`) na seção e nas páginas dela. */
export function SiteNavLink({ href, children }: { href: string; children: React.ReactNode }) {
  const pathname = usePathname()
  const active = pathname === href || pathname.startsWith(`${href}/`)

  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={cn(
        "text-muted-foreground hover:text-foreground",
        active && "font-medium text-foreground",
      )}
    >
      {children}
    </Link>
  )
}
