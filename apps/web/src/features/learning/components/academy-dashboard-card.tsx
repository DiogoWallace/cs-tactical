import Link from "next/link"
import {
  ArrowRightIcon,
  BookOpenIcon,
  CheckCircle2Icon,
  CompassIcon,
  GraduationCapIcon,
  LogInIcon,
  RotateCcwIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { ACADEMY_PATH, lessonPath } from "@/features/academy/lib/paths"
import { HOME_PATH, loginUrl } from "@/lib/routes"
import { cn } from "@/lib/utils"

import type { EnrollmentsLoad } from "../api/progress"
import { MY_PROGRESS_PATH } from "../lib/paths"
import type { EnrollmentSummary } from "../lib/schemas"
import { ProgressSummary } from "./progress-summary"

/**
 * Curso para "continuar estudando" (etapa 2.6, docs/development/fase-2-plano.md, D2).
 *
 * Critério determinístico, só com dados da API: a primeira matrícula de curso público com próxima
 * aula, na ordem de `/me/enrollments` (`last_activity_at` mais recente primeiro, empate pelo id).
 * `last_activity_at` muda só com a matrícula, uma conclusão nova de aula, uma tentativa de quiz nova
 * e o envio dela (R10, docs/architecture/academy.md): é a última atividade registrada, não o último
 * acesso (abrir uma aula não grava nada).
 */
export function pickCourseToContinue(enrollments: EnrollmentSummary[]): EnrollmentSummary | null {
  return (
    enrollments.find((item) => item.course.status === "published" && item.next_lesson !== null) ??
    null
  )
}

export type AcademyTotals = {
  /** Todas as matrículas, inclusive de cursos que saíram do catálogo (histórico). */
  enrolled: number
  /** Cursos com `completed_at` (concluídos, mesmo que depois tenham ganhado aulas novas). */
  completed: number
  /** Conclusões gravadas, somadas em todos os cursos. */
  completedLessons: number
}

export function academyTotals(enrollments: EnrollmentSummary[]): AcademyTotals {
  return {
    enrolled: enrollments.length,
    completed: enrollments.filter((item) => item.completed_at !== null).length,
    completedLessons: enrollments.reduce((sum, item) => sum + item.progress.completed_lessons, 0),
  }
}

const TITLE_ID = "dashboard-academy-title"

function Shell({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <Card
      className={cn("md:col-span-2 md:row-span-2", className)}
      data-testid="dashboard-academy"
      aria-labelledby={TITLE_ID}
      role="region"
    >
      <CardHeader>
        <CardTitle className="text-lg">
          <h3 id={TITLE_ID} className="flex items-center gap-2">
            <GraduationCapIcon className="size-5 text-primary" aria-hidden />
            Academy
          </h3>
        </CardTitle>
        <CardDescription>Seus cursos, o que já concluiu e de onde continuar.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-6">{children}</CardContent>
    </Card>
  )
}

function ExploreButton({ primary = false }: { primary?: boolean }) {
  return (
    <Button asChild variant={primary ? "default" : "outline"}>
      <Link href={ACADEMY_PATH}>
        <CompassIcon aria-hidden />
        Explorar cursos
      </Link>
    </Button>
  )
}

/**
 * Cartão da Academy no painel, com os dados reais de `GET /api/v1/me/enrollments`.
 * Estados: sem matrícula, em andamento, tudo concluído, API indisponível e sessão expirada
 * (o carregamento é o `AcademyDashboardCardSkeleton`, no `<Suspense>` da página).
 * Datas no fuso do perfil do jogador.
 */
export function AcademyDashboardCard({
  load,
  timeZone,
}: {
  load: EnrollmentsLoad
  timeZone: string
}) {
  if (load.kind === "anonymous") {
    return (
      <Shell>
        <p role="alert" className="text-sm text-muted-foreground">
          Sua sessão expirou. Entre novamente para ver seu progresso na Academy.
        </p>
        <div className="flex flex-wrap gap-3">
          <Button asChild>
            <Link href={loginUrl(HOME_PATH)}>
              <LogInIcon aria-hidden />
              Entrar novamente
            </Link>
          </Button>
          <ExploreButton />
        </div>
      </Shell>
    )
  }

  if (load.kind === "unavailable") {
    return (
      <Shell>
        <p role="alert" className="text-sm text-muted-foreground">
          Não foi possível carregar seu progresso agora. Tente de novo em instantes.
        </p>
        <div className="flex flex-wrap gap-3">
          <Button asChild variant="outline">
            {/* Link comum (não <Link>), como em AcademyUnavailable: recarrega o painel e refaz a
                leitura no servidor; uma navegação do router para a mesma página poderia reaproveitar
                o resultado anterior. */}
            <a href={HOME_PATH}>
              <RotateCcwIcon aria-hidden />
              Tentar novamente
            </a>
          </Button>
          <ExploreButton />
        </div>
      </Shell>
    )
  }

  const { enrollments } = load
  if (enrollments.length === 0) {
    return (
      <Shell>
        <div className="flex flex-col items-start gap-2">
          <BookOpenIcon aria-hidden className="size-6 text-muted-foreground" />
          <p className="font-medium">Você ainda não está em nenhum curso</p>
          <p className="text-sm text-muted-foreground">
            Escolha um curso na Academy e matricule-se para acompanhar seu progresso aqui.
          </p>
        </div>
        <div className="mt-auto flex flex-wrap gap-3">
          <ExploreButton primary />
        </div>
      </Shell>
    )
  }

  const totals = academyTotals(enrollments)
  const current = pickCourseToContinue(enrollments)
  const date = new Intl.DateTimeFormat("pt-BR", { dateStyle: "medium", timeZone })

  return (
    <Shell>
      <dl className="grid grid-cols-3 gap-3">
        <Stat label={totals.enrolled === 1 ? "Curso matriculado" : "Cursos matriculados"}>
          {totals.enrolled}
        </Stat>
        <Stat label={totals.completed === 1 ? "Curso concluído" : "Cursos concluídos"}>
          {totals.completed}
        </Stat>
        <Stat label={totals.completedLessons === 1 ? "Aula concluída" : "Aulas concluídas"}>
          {totals.completedLessons}
        </Stat>
      </dl>

      {current?.next_lesson ? (
        <div className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
          <div className="flex flex-col gap-1">
            <p className="text-xs font-medium tracking-widest text-primary uppercase">
              {current.completed_at ? "Aula nova no curso" : "Continue de onde parou"}
            </p>
            <p className="text-base font-semibold">{current.course.title}</p>
            <p className="text-sm text-muted-foreground">
              Próxima aula: {current.next_lesson.title}
            </p>
          </div>
          {current.progress.percent !== null && current.progress.total_lessons !== null && (
            <ProgressSummary
              percent={current.progress.percent}
              completed={current.progress.completed_lessons}
              total={current.progress.total_lessons}
            />
          )}
          <p className="text-xs text-muted-foreground">
            Última atividade registrada em {date.format(new Date(current.last_activity_at))}
          </p>
        </div>
      ) : (
        <p className="inline-flex items-center gap-2 text-sm font-medium text-success">
          <CheckCircle2Icon aria-hidden className="size-4" />
          Você está em dia: nenhuma aula pendente nos seus cursos.
        </p>
      )}

      <div className="mt-auto flex flex-wrap gap-3">
        {current?.next_lesson && (
          <Button asChild>
            <Link href={lessonPath(current.course.slug, current.next_lesson.slug)}>
              Continuar estudando
              <ArrowRightIcon aria-hidden />
            </Link>
          </Button>
        )}
        <Button asChild variant="outline">
          <Link href={MY_PROGRESS_PATH}>Ver meu progresso</Link>
        </Button>
        <ExploreButton />
      </div>
    </Shell>
  )
}

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col-reverse gap-1 rounded-lg border p-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-mono text-2xl font-semibold">{children}</dd>
    </div>
  )
}

export function AcademyDashboardCardSkeleton() {
  return (
    <Card
      className="md:col-span-2 md:row-span-2"
      role="status"
      aria-busy="true"
      aria-label="Carregando seu progresso na Academy"
    >
      <CardHeader>
        <Skeleton className="h-6 w-32" />
        <Skeleton className="h-4 w-64" />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-3 gap-3">
          <Skeleton className="h-16" />
          <Skeleton className="h-16" />
          <Skeleton className="h-16" />
        </div>
        <Skeleton className="h-28" />
        <Skeleton className="h-9 w-48" />
      </CardContent>
    </Card>
  )
}
