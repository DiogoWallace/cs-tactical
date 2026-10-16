import type { Metadata } from "next"
import { GeistMono } from "geist/font/mono"
import { GeistSans } from "geist/font/sans"

import { ThemeProvider } from "@/components/theme-provider"
import { cn } from "@/lib/utils"

import "./globals.css"

export const metadata: Metadata = {
  title: {
    default: "CS Tactical",
    template: "%s · CS Tactical",
  },
  description: "Treinamento individual e gestão de equipes competitivas de Counter-Strike 2.",
}

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    // suppressHydrationWarning: o next-themes ajusta a classe do <html> antes da hidratação.
    <html
      lang="pt-BR"
      className={cn(GeistSans.variable, GeistMono.variable, "h-full")}
      suppressHydrationWarning
    >
      <body className="flex min-h-full flex-col">
        {/* Cabeçalho e <main> ficam nos layouts de cada grupo: (marketing), (auth) e (app). */}
        <ThemeProvider>{children}</ThemeProvider>
      </body>
    </html>
  )
}
