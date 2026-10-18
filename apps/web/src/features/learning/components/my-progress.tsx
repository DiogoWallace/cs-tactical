import Link from "next/link"
import { ArchiveIcon, ArrowRightIcon, BookOpenIcon, CheckCircle2Icon } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty"
import { ACADEMY_PATH, coursePath, lessonPath } from "@/features/academy/lib/paths"

import type { EnrollmentSummary } from "../lib/schemas"
import { ProgressSummary } from "./progress-summary"

/**
 * "Meu progresso" (etapa 2.4): matrículas do jogador, mais recentes primeiro (como a API devolve).
 * Cursos que deixaram de ser públicos ficam no histórico, sem link nem percentual (R13a): o
 * conteúdo arquivado não pode mais ser aberto, mas as conclusões continuam do jogador.
 *
 * Datas no fuso do perfil do jogador.
 */
export function MyProgress({
  enrollments,
  timeZone,
}: {
  enrollments: EnrollmentSummary[]
  timeZone: string
}) {
  if (enrollments.length === 0) {
    return (
      <Empty className="border">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <BookOpenIcon />
          </EmptyMedia>
          <EmptyTitle>Você ainda não está em nenhum curso</EmptyTitle>
          <EmptyDescription>
            Escolha um curso na Academy e matricule-se para acompanhar seu progresso aqui.
          </EmptyDescription>
        </EmptyHeader>
        <EmptyContent>
          <Button asChild>
            <Link href={ACADEMY_PATH}>Ver cursos</Link>
          </Button>
        </EmptyContent>
      </Empty>
    )
  }

  const active = enrollments.filter((item) => item.course.status === "published")
  const archived = enrollments.filter((item) => item.course.status === "archived")
  const date = new Intl.DateTimeFormat("pt-BR", { dateStyle: "medium", timeZone })

  return (
    <div className="flex flex-col gap-10">
      {active.length > 0 && (
        <section aria-labelledby="my-courses" className="flex flex-col gap-4">
          <h2 id="my-courses" className="text-xl font-semibold tracking-tight">
            Meus cursos
          </h2>
          <ul className="grid gap-4 md:grid-cols-2">
            {active.map((item) => (
              <li
                key={item.course.slug}
                className="flex flex-col gap-4 rounded-xl border bg-card p-5"
              >
                <div className="flex flex-col gap-1">
                  <h3 className="text-lg font-semibold">
                    <Link href={coursePath(item.course.slug)} className="hover:text-primary">
                      {item.course.title}
                    </Link>
                  </h3>
                  <p className="text-sm text-muted-foreground">
                    Matrícula em {date.format(new Date(item.enrolled_at))}
                  </p>
                </div>
                {item.progress.percent !== null && item.progress.total_lessons !== null && (
                  <ProgressSummary
                    percent={item.progress.percent}
                    completed={item.progress.completed_lessons}
                    total={item.progress.total_lessons}
                  />
                )}
                {item.completed_at !== null && (
                  <p className="inline-flex items-center gap-2 text-sm font-medium text-success">
                    <CheckCircle2Icon aria-hidden className="size-4" />
                    Concluído em {date.format(new Date(item.completed_at))}
                  </p>
                )}
                {item.next_lesson && (
                  <Button
                    asChild
                    variant={item.completed_at ? "outline" : "default"}
                    className="w-fit"
                  >
                    <Link href={lessonPath(item.course.slug, item.next_lesson.slug)}>
                      {item.completed_at ? "Nova aula" : "Continuar"}: {item.next_lesson.title}
                      <ArrowRightIcon aria-hidden />
                    </Link>
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {archived.length > 0 && (
        <section aria-labelledby="archived-courses" className="flex flex-col gap-4">
          <div className="flex flex-col gap-1">
            <h2 id="archived-courses" className="text-xl font-semibold tracking-tight">
              Histórico
            </h2>
            <p className="text-sm text-muted-foreground">
              Cursos que saíram do catálogo. Suas conclusões continuam registradas.
            </p>
          </div>
          <ul className="flex flex-col gap-3">
            {archived.map((item) => (
              <li
                key={item.course.slug}
                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed p-4"
              >
                <div className="flex flex-col gap-1">
                  <p className="font-medium">{item.course.title}</p>
                  <p className="text-sm text-muted-foreground">
                    {item.progress.completed_lessons}{" "}
                    {item.progress.completed_lessons === 1 ? "aula concluída" : "aulas concluídas"}
                    {item.completed_at !== null &&
                      ` · curso concluído em ${date.format(new Date(item.completed_at))}`}
                  </p>
                </div>
                <Badge variant="outline">
                  <ArchiveIcon aria-hidden />
                  Arquivado
                </Badge>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
