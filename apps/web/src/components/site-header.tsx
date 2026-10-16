import Link from "next/link"

import { ThemeToggle } from "@/components/theme-toggle"

export function SiteHeader() {
  return (
    <header className="border-b">
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
        <Link href="/" className="font-semibold tracking-tight">
          CS <span className="text-primary">Tactical</span>
        </Link>
        <ThemeToggle />
      </div>
    </header>
  )
}
