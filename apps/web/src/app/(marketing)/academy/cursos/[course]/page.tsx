import type { Metadata } from "next"
import { notFound } from "next/navigation"
import { Suspense } from "react"

import { getCourse } from "@/features/academy/api/catalog"
import { AcademyUnavailable } from "@/features/academy/components/catalog"
import {
  CourseOverview,
  CourseOverviewSkeleton,
} from "@/features/academy/components/course-overview"
import { coursePath } from "@/features/academy/lib/paths"
import { getCourseProgress } from "@/features/learning/api/progress"
import { CourseLearningProvider } from "@/features/learning/components/course-learning-provider"
import { EnrollmentPanel } from "@/features/learning/components/enrollment-panel"
import { LessonStatusMark } from "@/features/learning/components/lesson-status-mark"

type Props = PageProps<"/academy/cursos/[course]">

/** Metadados só de curso público (a API nunca é chamada com `include_unpublished`). */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { course } = await params
  const result = await getCourse(course)
  if (result.status !== "ok") {
    return { title: "Academy", robots: { index: false, follow: false } }
  }

  const { title, summary, slug, cover_image_url: cover } = result.data
  return {
    title,
    description: summary,
    alternates: { canonical: coursePath(slug) },
    openGraph: {
      type: "website",
      title,
      description: summary,
      url: coursePath(slug),
      ...(cover ? { images: [{ url: cover }] } : {}),
    },
  }
}

export default function CoursePage({ params }: Props) {
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-col px-4 py-10 sm:px-6">
      <Suspense fallback={<CourseOverviewSkeleton />}>
        <CourseContent params={params} />
      </Suspense>
    </div>
  )
}

async function CourseContent({ params }: { params: Props["params"] }) {
  const { course } = await params
  // Em paralelo: o curso (público, igual para todos) e o progresso do jogador (só com sessão).
  const [result, learning] = await Promise.all([getCourse(course), getCourseProgress(course)])

  if (result.status === "not_found") notFound()
  if (result.status === "unavailable") return <AcademyUnavailable retryHref={coursePath(course)} />
  return (
    <CourseLearningProvider course={result.data.slug} initial={learning}>
      <CourseOverview
        course={result.data}
        learningPanel={<EnrollmentPanel />}
        renderLessonStatus={(lesson) => <LessonStatusMark lesson={lesson} />}
      />
    </CourseLearningProvider>
  )
}
