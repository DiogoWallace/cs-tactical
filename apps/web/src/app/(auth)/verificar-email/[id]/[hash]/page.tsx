import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { VerifyEmailStatus } from "@/features/identity/components/verify-email-status"

export const metadata: Metadata = {
  title: "Confirmar e-mail",
  // Nenhuma URL desta página vai para outros sites. "same-origin", e não "no-referrer": o
  // Sanctum reconhece a sessão pelo Referer nas chamadas GET à própria API.
  referrer: "same-origin",
}

export default function VerifyEmailPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Confirmar e-mail</CardTitle>
        <CardDescription>Link recebido por e-mail após o cadastro.</CardDescription>
      </CardHeader>
      <CardContent>
        {/* Lê a URL (parâmetros e query): fica atrás de Suspense (Cache Components). */}
        <Suspense>
          <VerifyEmailStatus />
        </Suspense>
      </CardContent>
    </Card>
  )
}
