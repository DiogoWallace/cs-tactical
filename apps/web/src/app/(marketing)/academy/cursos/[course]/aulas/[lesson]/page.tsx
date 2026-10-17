import type { Metadata } from "next"
import { notFound } from "next/navigation"
import { Suspense } from "react"

import { getCourse, getLesson } from "@/features/academy/api/catalog"
import { AcademyUnavailable } from "@/features/academy/components/catalog"
import { LessonSkeleton, LessonView } from "@/features/academy/components/lesson-view"
import { lessonPath } from "@/features/academy/lib/paths"

type Props = PageProps<"/academy/cursos/[course]/aulas/[lesson]">

/** Metadados só de aula pública; o texto vem do título e do resumo, nunca do conteúdo. */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { course, lesson } = await params
  const result = await getLesson(course, lesson)
  if (result.status !== "ok") {
    return { title: "Academy", robots: { index: false, follow: false } }
  }

  const { title, summary, slug, course: parent } = result.data
  const description = summary ?? `Aula do curso ${parent.title} na Academy do CS Tactical.`
  return {
    title: `${title} · ${parent.title}`,
    description,
    alternates: { canonical: lessonPath(parent.slug, slug) },
    openGraph: { type: "article", title, description, url: lessonPath(parent.slug, slug) },
  }
}

/** Página de aula. A CSP desta rota libera só o player do YouTube sem cookies (ADR 0019). */
export default function LessonPage({ params }: Props) {
  return (
    <div className="mx-auto flex w-full max-w-7xl flex-col px-4 py-8 sm:px-6 lg:py-10">
      <Suspense fallback={<LessonSkeleton />}>
        <LessonContent params={params} />
      </Suspense>
    </div>
  )
}

async function LessonContent({ params }: { params: Props["params"] }) {
  const { course, lesson } = await params
  // Em paralelo: a aula e o curso (para a lista de módulos e a posição da aula).
  const [lessonResult, courseResult] = await Promise.all([
    getLesson(course, lesson),
    getCourse(course),
  ])

  if (lessonResult.status === "not_found" || courseResult.status === "not_found") notFound()
  if (lessonResult.status === "unavailable" || courseResult.status === "unavailable") {
    return <AcademyUnavailable retryHref={lessonPath(course, lesson)} />
  }
  return <LessonView course={courseResult.data} lesson={lessonResult.data} />
}
