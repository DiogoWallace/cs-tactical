import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { ForgotPasswordForm } from "@/features/identity/components/forgot-password-form"
import { RedirectIfAuthenticated } from "@/features/identity/components/redirect-if-authenticated"

export const metadata: Metadata = {
  title: "Esqueci minha senha",
}

export default function ForgotPasswordPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Esqueci minha senha</CardTitle>
        <CardDescription>Informe seu e-mail para receber um link de redefinição.</CardDescription>
      </CardHeader>
      <CardContent>
        {/* Lê cookies: fica atrás de Suspense (Cache Components). */}
        <Suspense>
          <RedirectIfAuthenticated />
        </Suspense>
        <ForgotPasswordForm />
      </CardContent>
    </Card>
  )
}
