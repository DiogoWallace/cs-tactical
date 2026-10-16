"use client"

import { ThemeProvider as NextThemesProvider } from "next-themes"

/** Tema escuro como padrão; o usuário pode alternar para o claro. */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  return (
    <NextThemesProvider
      attribute="class"
      defaultTheme="dark"
      enableSystem={false}
      disableTransitionOnChange
    >
      {children}
    </NextThemesProvider>
  )
}
