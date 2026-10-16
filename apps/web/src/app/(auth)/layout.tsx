import Link from "next/link"

import { ThemeToggle } from "@/components/theme-toggle"

/** Login e cadastro: coluna central, sem a navegação do site. */
export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <header className="mx-auto flex h-14 w-full max-w-6xl items-center justify-between px-4 sm:px-6">
        <Link href="/" className="font-semibold tracking-tight">
          CS <span className="text-primary">Tactical</span>
        </Link>
        <ThemeToggle />
      </header>
      <main className="flex flex-1 flex-col items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">{children}</div>
      </main>
    </>
  )
}
