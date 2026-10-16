import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { LoginForm } from "@/features/identity/components/login-form"
import { RedirectIfAuthenticated } from "@/features/identity/components/redirect-if-authenticated"

export const metadata: Metadata = {
  title: "Entrar",
}

export default function LoginPage() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-xl">Entrar</CardTitle>
        <CardDescription>Acesse sua conta do CS Tactical.</CardDescription>
      </CardHeader>
      <CardContent>
        {/* Dependem da requisição (cookies e ?next=): ficam atrás de Suspense (Cache Components). */}
        <Suspense>
          <RedirectIfAuthenticated />
        </Suspense>
        <Suspense fallback={<FormSkeleton />}>
          <LoginForm />
        </Suspense>
      </CardContent>
    </Card>
  )
}

function FormSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-hidden>
      <Skeleton className="h-14" />
      <Skeleton className="h-14" />
      <Skeleton className="h-9" />
    </div>
  )
}
