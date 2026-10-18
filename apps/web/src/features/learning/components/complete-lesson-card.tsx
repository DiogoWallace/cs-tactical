"use client"

import { useState, useTransition } from "react"
import Link from "next/link"
import { CheckCircle2Icon, LogInIcon, RefreshCwIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { lessonPath } from "@/features/academy/lib/paths"
import { loginUrl } from "@/lib/routes"

import { completeLesson, learningErrorMessage } from "../api/learning"
import { formatPercent } from "../lib/schemas"
import { useHydrated } from "../lib/use-hydrated"
import { useCourseLearning } from "./course-learning-provider"
import { ProgressSummary } from "./progress-summary"

/**
 * Conclusão da aula (etapa 2.4, R8, R9). Concluir matricula automaticamente quem ainda não está
 * matriculado. Depois da conclusão, a resposta da API atualiza o progresso da página inteira
 * (marcas na lista de aulas, barra) e o resultado é anunciado (`aria-live`).
 *
 * Visitante: convite para entrar, voltando para esta aula.
 */
export function CompleteLessonCard({ lesson }: { lesson: string }) {
  const learning = useCourseLearning()
  const [error, setError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState("")
  const [pending, startTransition] = useTransition()
  const hydrated = useHydrated()

  if (learning === null) return null
  const { course, state, setProgress } = learning

  const progress = state.kind === "player" && state.progress.enrolled ? state.progress : null
  const item = progress?.lessons.find((entry) => entry.slug === lesson) ?? null
  const completed = item?.status === "completed"

  function complete() {
    setError(null)
    startTransition(async () => {
      try {
        const updated = await completeLesson(course, lesson)
        setProgress(updated)
        setAnnouncement(
          updated.enrolled
            ? `Aula concluída. Progresso do curso: ${formatPercent(updated.percent)}.`
            : "Aula concluída.",
        )
      } catch (failure) {
        setError(learningErrorMessage(failure))
      }
    })
  }

  return (
    <section
      aria-label="Conclusão da aula"
      className="flex max-w-3xl flex-col gap-4 rounded-xl border bg-card p-5"
      data-testid="lesson-completion"
    >
      {state.kind === "anonymous" && (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Entre para marcar as aulas concluídas e salvar seu progresso.
          </p>
          <Button asChild variant="outline" className="w-fit">
            <Link href={loginUrl(lessonPath(course, lesson))}>
              <LogInIcon aria-hidden />
              Entrar
            </Link>
          </Button>
        </div>
      )}

      {state.kind === "unavailable" && (
        <p className="text-sm text-muted-foreground">
          Não foi possível carregar seu progresso agora.
        </p>
      )}

      {state.kind === "player" &&
        (completed ? (
          <div className="flex flex-col gap-1">
            <p className="inline-flex items-center gap-2 font-medium text-success">
              <CheckCircle2Icon aria-hidden className="size-5" />
              Aula concluída
            </p>
            {item?.updated_since_completion && (
              <p className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                <RefreshCwIcon aria-hidden className="size-4" />O conteúdo foi atualizado depois que
                você concluiu. Sua conclusão continua valendo.
              </p>
            )}
          </div>
        ) : (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-muted-foreground">
              {progress
                ? "Terminou a leitura? Marque para salvar seu progresso."
                : "Concluir esta aula também faz sua matrícula no curso."}
            </p>
            <Button onClick={complete} disabled={pending || !hydrated} className="w-fit">
              <CheckCircle2Icon aria-hidden />
              {pending ? "Salvando..." : "Marcar aula como concluída"}
            </Button>
          </div>
        ))}

      {progress && (
        <ProgressSummary
          percent={progress.percent}
          completed={progress.completed_lessons}
          total={progress.total_lessons}
        />
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
