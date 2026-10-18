"use client"

import { useState, useTransition } from "react"
import Link from "next/link"
import { ArrowRightIcon, GraduationCapIcon, LogInIcon, PartyPopperIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { lessonPath, coursePath } from "@/features/academy/lib/paths"
import { loginUrl } from "@/lib/routes"

import { enrollInCourse, learningErrorMessage } from "../api/learning"
import { MY_PROGRESS_PATH } from "../lib/paths"
import { useHydrated } from "../lib/use-hydrated"
import { useCourseLearning } from "./course-learning-provider"
import { ProgressSummary } from "./progress-summary"

/**
 * Matrícula e progresso na página do curso (etapa 2.4, R7).
 *
 * - Visitante: convite para entrar (volta para o curso depois do login); a leitura continua livre.
 * - Sem matrícula: botão de matrícula.
 * - Matriculado: progresso, "continuar" na primeira aula pública não concluída e, se o curso foi
 *   concluído e ganhou aulas depois, o aviso de novas aulas (ADR 0017).
 *
 * O resultado das ações é anunciado numa região `aria-live`.
 */
export function EnrollmentPanel() {
  const learning = useCourseLearning()
  const [error, setError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState("")
  const [pending, startTransition] = useTransition()
  const hydrated = useHydrated()

  if (learning === null) return null
  const { course, state, setProgress } = learning

  function enroll() {
    setError(null)
    startTransition(async () => {
      try {
        setProgress(await enrollInCourse(course))
        setAnnouncement("Matrícula feita. Seu progresso neste curso será salvo.")
      } catch (failure) {
        setError(learningErrorMessage(failure))
      }
    })
  }

  return (
    <section
      aria-labelledby="enrollment-title"
      className="flex max-w-xl flex-col gap-4 rounded-xl border bg-card p-5"
    >
      <h2 id="enrollment-title" className="flex items-center gap-2 text-base font-semibold">
        <GraduationCapIcon aria-hidden className="size-5 text-primary" />
        Seu progresso
      </h2>

      {state.kind === "anonymous" && (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted-foreground">
            Todas as aulas estão abertas para leitura. Entre para se matricular e acompanhar o que
            já concluiu.
          </p>
          <Button asChild variant="outline" className="w-fit">
            <Link href={loginUrl(coursePath(course))}>
              <LogInIcon aria-hidden />
              Entrar para se matricular
            </Link>
          </Button>
        </div>
      )}

      {state.kind === "unavailable" && (
        <p className="text-sm text-muted-foreground">
          Não foi possível carregar seu progresso agora. O conteúdo continua disponível.
        </p>
      )}

      {state.kind === "player" && !state.progress.enrolled && (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted-foreground">
            Matricule-se para salvar as aulas concluídas e continuar de onde parou.
          </p>
          <Button onClick={enroll} disabled={pending || !hydrated} className="w-fit">
            {pending ? "Matriculando..." : "Matricular-se no curso"}
          </Button>
        </div>
      )}

      {state.kind === "player" && state.progress.enrolled && (
        <div className="flex flex-col gap-4">
          <ProgressSummary
            percent={state.progress.percent}
            completed={state.progress.completed_lessons}
            total={state.progress.total_lessons}
          />
          {state.progress.completed_at !== null && (
            <p className="inline-flex items-center gap-2 text-sm font-medium text-success">
              <PartyPopperIcon aria-hidden className="size-4" />
              Curso concluído
              {state.progress.new_lessons_available && (
                <span className="font-normal text-muted-foreground">· novas aulas disponíveis</span>
              )}
            </p>
          )}
          <div className="flex flex-wrap items-center gap-3">
            {state.progress.next_lesson && (
              <Button asChild>
                <Link href={lessonPath(course, state.progress.next_lesson.slug)}>
                  Continuar: {state.progress.next_lesson.title}
                  <ArrowRightIcon aria-hidden />
                </Link>
              </Button>
            )}
            <Link
              href={MY_PROGRESS_PATH}
              className="text-sm text-muted-foreground hover:text-foreground"
            >
              Ver meu progresso
            </Link>
          </div>
        </div>
      )}

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <p aria-live="polite" className="sr-only">
        {announcement}
      </p>
    </section>
  )
}
