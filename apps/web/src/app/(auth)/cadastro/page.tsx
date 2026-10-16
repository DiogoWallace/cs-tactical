import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { RedirectIfAuthenticated } from "@/features/identity/components/redirect-if-authenticated"
import { RegisterForm } from "@/features/identity/components/register-form"

export const metadata: Metadata = {
  title: "Criar conta",
}

export default function RegisterPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Criar conta</CardTitle>
        <CardDescription>Comece a treinar com o CS Tactical.</CardDescription>
      </CardHeader>
      <CardContent>
        {/* Lê cookies: fica atrás de Suspense (Cache Components). */}
        <Suspense>
          <RedirectIfAuthenticated />
        </Suspense>
        <RegisterForm />
      </CardContent>
    </Card>
  )
}
