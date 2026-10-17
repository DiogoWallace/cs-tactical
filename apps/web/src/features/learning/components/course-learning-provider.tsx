"use client"

import { createContext, useContext, useMemo, useState } from "react"

import type { LearningLoad } from "../api/progress"
import type { CourseProgress, LessonProgress } from "../lib/schemas"

/**
 * Estado do Learning de um curso na página (curso ou aula). Começa com o que o servidor leu da API
 * e é trocado pela resposta de cada escrita (matrícula, conclusão): a interface nunca inventa
 * progresso, e não precisa recarregar a página para refletir a mudança.
 */
type CourseLearning = {
  course: string
  state: LearningLoad
  setProgress: (progress: CourseProgress) => void
}

const CourseLearningContext = createContext<CourseLearning | null>(null)

export function CourseLearningProvider({
  course,
  initial,
  children,
}: {
  course: string
  initial: LearningLoad
  children: React.ReactNode
}) {
  const [state, setState] = useState<LearningLoad>(initial)
  const value = useMemo(
    () => ({
      course,
      state,
      setProgress: (progress: CourseProgress) => setState({ kind: "player", progress }),
    }),
    [course, state],
  )

  return <CourseLearningContext.Provider value={value}>{children}</CourseLearningContext.Provider>
}

/** null fora de um provider (ex.: componentes do catálogo renderizados sem Learning). */
export function useCourseLearning(): CourseLearning | null {
  return useContext(CourseLearningContext)
}

/** Progresso do jogador numa aula, se ele estiver matriculado. */
export function useLessonProgress(lesson: string): LessonProgress | null {
  const learning = useCourseLearning()
  if (learning?.state.kind !== "player" || !learning.state.progress.enrolled) return null
  return learning.state.progress.lessons.find((item) => item.slug === lesson) ?? null
}
