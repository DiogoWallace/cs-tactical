import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmEmailChangeStatus } from "@/features/identity/components/confirm-email-change-status"

export const metadata: Metadata = {
  title: "Confirmar novo e-mail",
  // Nenhuma URL desta página vai para outros sites. "same-origin", e não "no-referrer": o
  // Sanctum reconhece a sessão pelo Referer nas chamadas GET à própria API.
  referrer: "same-origin",
}

export default function ConfirmEmailChangePage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Confirmar novo e-mail</CardTitle>
        <CardDescription>Link enviado ao endereço que você pediu para usar.</CardDescription>
      </CardHeader>
      <CardContent>
        {/* Lê a URL (parâmetros e query): fica atrás de Suspense (Cache Components). */}
        <Suspense>
          <ConfirmEmailChangeStatus />
        </Suspense>
      </CardContent>
    </Card>
  )
}
