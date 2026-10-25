import type { Metadata } from "next"
import { Suspense } from "react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { getCurrentUser } from "@/features/identity/api/get-current-user"
import { EmailChangeForm } from "@/features/identity/components/email-change-form"
import { PasswordChangeForm } from "@/features/identity/components/password-change-form"
import { ProfileForm } from "@/features/identity/components/profile-form"
import { SteamLinkCard } from "@/features/identity/components/steam-link-card"

export const metadata: Metadata = {
  title: "Conta",
}

export default function AccountPage() {
  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-semibold tracking-tight">Conta</h1>
        <p className="text-muted-foreground">Perfil, e-mail, senha e conta Steam.</p>
      </header>
      {/* Lê a sessão: fica atrás de Suspense (Cache Components). */}
      <Suspense fallback={<AccountSkeleton />}>
        <AccountSections />
      </Suspense>
    </div>
  )
}

async function AccountSections() {
  const user = await getCurrentUser()

  return (
    <div className="grid max-w-xl gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Perfil</CardTitle>
          <CardDescription>Como você aparece na plataforma.</CardDescription>
        </CardHeader>
        <CardContent>
          <ProfileForm user={user} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">E-mail</CardTitle>
          <CardDescription>
            O novo endereço precisa ser confirmado pelo link que enviaremos a ele.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <EmailChangeForm user={user} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Senha</CardTitle>
          <CardDescription>Ao trocar, as outras sessões abertas são encerradas.</CardDescription>
        </CardHeader>
        <CardContent>
          <PasswordChangeForm />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Conta Steam</CardTitle>
          <CardDescription>
            Prova, pelo login da Steam, qual conta Steam é sua. Só o SteamID64 é guardado.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <SteamLinkCard user={user} />
        </CardContent>
      </Card>
    </div>
  )
}

function AccountSkeleton() {
  return (
    <div className="grid max-w-xl gap-6" aria-hidden>
      <Skeleton className="h-96" />
      <Skeleton className="h-64" />
      <Skeleton className="h-64" />
      <Skeleton className="h-48" />
    </div>
  )
}
