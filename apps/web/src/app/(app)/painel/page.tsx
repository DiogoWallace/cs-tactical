import type { Metadata } from "next"
import Link from "next/link"
import { Suspense } from "react"
import { UserRoundCogIcon, UsersRoundIcon } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { getCurrentUser } from "@/features/identity/api/get-current-user"
import { ResendVerificationButton } from "@/features/identity/components/resend-verification-button"
import { getMyEnrollments, type EnrollmentsLoad } from "@/features/learning/api/progress"
import {
  AcademyDashboardCard,
  AcademyDashboardCardSkeleton,
} from "@/features/learning/components/academy-dashboard-card"
import { ACCOUNT_PATH } from "@/lib/routes"

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
  // As matrículas começam junto com o usuário (sem cascata); o cartão espera por elas no próprio
  // <Suspense>, e o resto do painel não depende delas.
  const enrollments = getMyEnrollments()
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

      <section aria-labelledby="dashboard-sections" className="flex flex-col gap-4">
        <h2 id="dashboard-sections" className="sr-only">
          Áreas da plataforma
        </h2>
        <div className="grid gap-4 md:grid-cols-3">
          <Suspense fallback={<AcademyDashboardCardSkeleton />}>
            <AcademyCard
              enrollments={enrollments}
              timeZone={user.profile?.timezone ?? "America/Sao_Paulo"}
            />
          </Suspense>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <UserRoundCogIcon className="size-5 text-primary" aria-hidden />
                Sua conta
              </CardTitle>
              <CardDescription className="flex flex-wrap items-center gap-2">
                <span className="break-all">{user.email}</span>
                {user.email_verified ? (
                  <Badge variant="secondary">Verificado</Badge>
                ) : (
                  <Badge variant="outline">Não verificado</Badge>
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button asChild variant="outline" size="sm">
                <Link href={ACCOUNT_PATH}>Editar perfil, e-mail e senha</Link>
              </Button>
            </CardContent>
          </Card>

          {/* Só informativo: Teams (Fase 3) ainda não existe. */}
          <UpcomingModule
            icon={<UsersRoundIcon className="size-5 text-primary" aria-hidden />}
            title="Teams"
            description="Sua equipe, estratégias, playbooks e treinos em um só lugar."
          />
        </div>
      </section>
    </div>
  )
}

async function AcademyCard({
  enrollments,
  timeZone,
}: {
  enrollments: Promise<EnrollmentsLoad>
  timeZone: string
}) {
  return <AcademyDashboardCard load={await enrollments} timeZone={timeZone} />
}

function UpcomingModule({
  icon,
  title,
  description,
}: {
  icon: React.ReactNode
  title: string
  description: string
}) {
  return (
    <Card className="border-dashed" data-testid={`upcoming-${title.toLowerCase()}`}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg">
          {icon}
          {title}
          <Badge variant="outline" className="ml-auto">
            Em breve
          </Badge>
        </CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  )
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-8" aria-hidden>
      <Skeleton className="h-10 w-64" />
      <div className="grid gap-4 md:grid-cols-3">
        <Skeleton className="h-84 md:col-span-2 md:row-span-2" />
        <Skeleton className="h-40" />
        <Skeleton className="h-40" />
      </div>
    </div>
  )
}
