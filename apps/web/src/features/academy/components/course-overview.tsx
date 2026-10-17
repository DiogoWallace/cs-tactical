import Link from "next/link"
import { ArrowLeftIcon, ClockIcon, ListChecksIcon, PlayIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"

import { ACADEMY_PATH, lessonPath } from "../lib/paths"
import type { Course } from "../lib/schemas"
import { CourseCover } from "./course-cover"
import { CourseStats, formatDuration, LevelBadge } from "./course-meta"

/** Totais do curso a partir dos módulos e aulas visíveis (os mesmos que a API devolveu). */
export function courseTotals(course: Course) {
  const lessons = course.modules.flatMap((module) => module.lessons)
  const minutes = lessons.reduce((total, lesson) => total + (lesson.estimated_minutes ?? 0), 0)
  return {
    moduleCount: course.modules.length,
    lessonCount: lessons.length,
    minutes: minutes > 0 ? minutes : null,
    firstLesson: lessons[0] ?? null,
  }
}

/**
 * Página de um curso: identificação, capa, números e a sequência de módulos e aulas.
 * Sem progresso nem matrícula (chegam com o Learning, etapa 2.4).
 */
export function CourseOverview({ course }: { course: Course }) {
  const { moduleCount, lessonCount, minutes, firstLesson } = courseTotals(course)
  // Número da aula na ordem do curso inteiro (a primeira do módulo 2 continua a contagem).
  const firstNumber = course.modules.map((_, index) =>
    course.modules.slice(0, index).reduce((total, module) => total + module.lessons.length, 1),
  )

  return (
    <div className="flex flex-col gap-12">
      <header className="grid items-center gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
        <div className="flex flex-col gap-5">
          <Link
            href={ACADEMY_PATH}
            className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeftIcon aria-hidden className="size-4" />
            Todos os cursos
          </Link>
          <div className="flex flex-col gap-3">
            <p className="text-sm font-medium tracking-widest text-primary uppercase">
              Curso da Academy
            </p>
            <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
              {course.title}
            </h1>
            <p className="max-w-2xl text-lg text-muted-foreground">{course.summary}</p>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <LevelBadge level={course.level} />
            <CourseStats moduleCount={moduleCount} lessonCount={lessonCount} minutes={minutes} />
          </div>
          {firstLesson && (
            <Button asChild size="lg" className="w-fit">
              <Link href={lessonPath(course.slug, firstLesson.slug)}>
                <PlayIcon aria-hidden />
                Começar pela primeira aula
              </Link>
            </Button>
          )}
        </div>
        <CourseCover url={course.cover_image_url} className="rounded-xl border shadow-sm" />
      </header>

      <section aria-labelledby="course-content" className="flex flex-col gap-6">
        <h2 id="course-content" className="text-2xl font-semibold tracking-tight">
          Conteúdo do curso
        </h2>
        {course.modules.length === 0 ? (
          <p className="text-muted-foreground">
            As aulas deste curso ainda estão sendo preparadas.
          </p>
        ) : (
          <ol className="flex flex-col gap-6">
            {course.modules.map((module, moduleIndex) => (
              <li key={module.slug} className="rounded-xl border bg-card">
                <div className="flex items-baseline gap-3 border-b px-5 py-4">
                  <span className="font-mono text-sm text-primary" aria-hidden>
                    {String(moduleIndex + 1).padStart(2, "0")}
                  </span>
                  <h3 className="text-lg font-semibold">
                    <span className="sr-only">Módulo {moduleIndex + 1}: </span>
                    {module.title}
                  </h3>
                </div>
                <ol className="divide-y">
                  {module.lessons.map((lesson, lessonIndex) => {
                    const order = (firstNumber[moduleIndex] ?? 1) + lessonIndex
                    return (
                      <li key={lesson.slug}>
                        <Link
                          href={lessonPath(course.slug, lesson.slug)}
                          className="flex items-center gap-4 px-5 py-3.5 transition-colors outline-none hover:bg-accent/60 focus-visible:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                        >
                          <span className="flex size-8 shrink-0 items-center justify-center rounded-full border font-mono text-xs text-muted-foreground">
                            <span className="sr-only">Aula </span>
                            {order}
                          </span>
                          <span className="min-w-0 flex-1 font-medium">{lesson.title}</span>
                          <span className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                            {lesson.has_quiz && (
                              <span className="inline-flex items-center gap-1">
                                <ListChecksIcon aria-hidden className="size-3.5" />
                                Quiz
                              </span>
                            )}
                            {lesson.estimated_minutes !== null && (
                              <span className="inline-flex items-center gap-1">
                                <ClockIcon aria-hidden className="size-3.5" />
                                {formatDuration(lesson.estimated_minutes)}
                              </span>
                            )}
                          </span>
                        </Link>
                      </li>
                    )
                  })}
                </ol>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  )
}

export function CourseOverviewSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Carregando curso"
      className="flex flex-col gap-12"
    >
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
        <div className="flex flex-col gap-4">
          <Skeleton className="h-4 w-32" />
          <Skeleton className="h-10 w-3/4" />
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-10 w-56" />
        </div>
        <Skeleton className="aspect-video rounded-xl" />
      </div>
      <Skeleton className="h-64 rounded-xl" />
    </div>
  )
}
