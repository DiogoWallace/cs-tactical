import type { Metadata } from "next"
import { Suspense } from "react"

import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { getCurrentUser } from "@/features/identity/api/get-current-user"
import { ResendVerificationButton } from "@/features/identity/components/resend-verification-button"

export const metadata: Metadata = {
  title: "Painel",
}

export default function DashboardPage() {
  return (
    <Suspense fallback={<DashboardSkeleton />}>
      <Dashboard />
    </Suspense>
  )
}

async function Dashboard() {
  const user = await getCurrentUser()
  const nickname = user.profile?.nickname ?? user.name

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-semibold tracking-tight">Olá, {nickname}</h1>
        <p className="text-muted-foreground">Bem-vindo à sua área no CS Tactical.</p>
      </header>

      {!user.email_verified && (
        <Card className="max-w-xl border-warning/50">
          <CardHeader>
            <CardTitle className="text-lg">Confirme seu e-mail</CardTitle>
            <CardDescription>
              Enviamos um link para {user.email}. Algumas funcionalidades exigem o e-mail
              confirmado.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ResendVerificationButton email={user.email} />
          </CardContent>
        </Card>
      )}

      <Card className="max-w-xl">
        <CardHeader>
          <CardTitle className="text-lg">Sua conta</CardTitle>
          <CardDescription>Dados da sessão atual.</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            <dt className="text-muted-foreground">Nome</dt>
            <dd>{user.name}</dd>
            <dt className="text-muted-foreground">E-mail</dt>
            <dd className="flex flex-wrap items-center gap-2">
              <span className="break-all">{user.email}</span>
              {user.email_verified ? (
                <Badge variant="secondary">Verificado</Badge>
              ) : (
                <Badge variant="outline">Não verificado</Badge>
              )}
            </dd>
            <dt className="text-muted-foreground">Apelido</dt>
            <dd>{user.profile?.nickname ?? "—"}</dd>
            <dt className="text-muted-foreground">Fuso horário</dt>
            <dd>{user.profile?.timezone ?? "UTC"}</dd>
          </dl>
        </CardContent>
      </Card>
    </div>
  )
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-8" aria-hidden>
      <Skeleton className="h-10 w-64" />
      <Skeleton className="h-48 max-w-xl" />
    </div>
  )
}
