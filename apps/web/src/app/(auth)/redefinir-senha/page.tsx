import type { Metadata } from "next"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { ResetPasswordForm } from "@/features/identity/components/reset-password-form"

export const metadata: Metadata = {
  title: "Redefinir senha",
  // Nenhuma URL desta página vai para outros sites. "same-origin", e não "no-referrer": o
  // Sanctum reconhece a sessão pelo Referer nas chamadas GET à própria API.
  referrer: "same-origin",
}

export default function ResetPasswordPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Redefinir senha</CardTitle>
        <CardDescription>Escolha uma nova senha para a sua conta.</CardDescription>
      </CardHeader>
      <CardContent>
        <ResetPasswordForm />
      </CardContent>
    </Card>
  )
}
