"use client"

import { CheckCircle2Icon } from "lucide-react"

import { useLessonProgress } from "./course-learning-provider"

/**
 * Marca de "concluída" ao lado de uma aula nas listas do curso. Só aparece para quem está
 * matriculado e concluiu; "atualizada" quando o conteúdo mudou depois da conclusão (ADR 0017).
 */
export function LessonStatusMark({ lesson }: { lesson: string }) {
  const progress = useLessonProgress(lesson)
  if (progress?.status !== "completed") return null

  return (
    <span
      className="inline-flex shrink-0 items-center gap-1 text-xs text-success"
      data-testid="lesson-completed"
    >
      <CheckCircle2Icon aria-hidden className="size-4" />
      <span className="sr-only">Concluída</span>
      {progress.updated_since_completion && (
        <span className="text-muted-foreground" title="O conteúdo mudou depois que você concluiu">
          atualizada
        </span>
      )}
    </span>
  )
}
