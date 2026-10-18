import Link from "next/link"
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  ClockIcon,
  ListChecksIcon,
} from "lucide-react"

import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

import { ACADEMY_PATH, coursePath, lessonPath } from "../lib/paths"
import type { Course, Lesson, QuizRefBlock } from "../lib/schemas"
import { LessonBlocks } from "./blocks/lesson-blocks"
import { formatDuration } from "./course-meta"

/** Posição da aula no curso (só entre as aulas visíveis que a API devolveu). */
export function lessonPosition(course: Course, lessonSlug: string) {
  const lessons = course.modules.flatMap((module) => module.lessons)
  const index = lessons.findIndex((lesson) => lesson.slug === lessonSlug)
  return { number: index + 1, total: lessons.length }
}

/**
 * Leitura de uma aula. Desktop: conteúdo e, ao lado, os módulos e aulas do curso (fixos ao
 * rolar). Celular: o conteúdo ocupa a largura toda; a lista de aulas fica recolhida acima dele
 * (`<details>`, funciona sem JavaScript e pelo teclado).
 */
export function LessonView({
  course,
  lesson,
  completion,
  renderLessonStatus,
  renderQuiz,
}: {
  course: Course
  lesson: Lesson
  /** Conclusão da aula (Learning, etapa 2.4), montada pela página: a Academy não depende do Learning. */
  completion?: React.ReactNode
  renderLessonStatus?: (lesson: string) => React.ReactNode
  /** Quiz respondível (Learning, etapa 2.5) no lugar do bloco `quiz_ref`. */
  renderQuiz?: (quiz: QuizRefBlock["data"]) => React.ReactNode
}) {
  const { number, total } = lessonPosition(course, lesson.slug)

  return (
    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem] xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="flex min-w-0 flex-col gap-8">
        <header className="flex flex-col gap-4">
          <nav aria-label="Trilha" className="text-sm text-muted-foreground">
            <ol className="flex flex-wrap items-center gap-1.5">
              <li>
                <Link href={ACADEMY_PATH} className="hover:text-foreground">
                  Academy
                </Link>
              </li>
              <li aria-hidden>
                <ChevronRightIcon className="size-3.5" />
              </li>
              <li>
                <Link href={coursePath(course.slug)} className="hover:text-foreground">
                  {course.title}
                </Link>
              </li>
              <li aria-hidden>
                <ChevronRightIcon className="size-3.5" />
              </li>
              <li className="text-foreground">{lesson.module.title}</li>
            </ol>
          </nav>

          <div className="flex flex-col gap-3">
            {number > 0 && (
              <p className="text-sm font-medium tracking-widest text-primary uppercase">
                Aula {number} de {total}
              </p>
            )}
            <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
              {lesson.title}
            </h1>
            {lesson.summary && <p className="text-lg text-muted-foreground">{lesson.summary}</p>}
            {lesson.estimated_minutes !== null && (
              <p className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                <ClockIcon aria-hidden className="size-4" />
                <span className="sr-only">Duração estimada: </span>
                {formatDuration(lesson.estimated_minutes)}
              </p>
            )}
          </div>
        </header>

        <details className="group rounded-lg border bg-card lg:hidden">
          <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-webkit-details-marker]:hidden">
            Aulas do curso
            <ChevronDownIcon
              aria-hidden
              className="size-4 transition-transform group-open:rotate-180 motion-reduce:transition-none"
            />
          </summary>
          <div className="border-t px-2 py-3">
            <CourseLessonsNav
              course={course}
              currentSlug={lesson.slug}
              idPrefix="celular"
              renderLessonStatus={renderLessonStatus}
            />
          </div>
        </details>

        <article className="max-w-3xl">
          {lesson.content.blocks.length > 0 ? (
            <LessonBlocks blocks={lesson.content.blocks} renderQuiz={renderQuiz} />
          ) : (
            <p className="text-muted-foreground">
              O conteúdo desta aula ainda não está disponível.
            </p>
          )}
        </article>

        {completion}

        <LessonPager course={course.slug} previous={lesson.previous} next={lesson.next} />
      </div>

      <aside className="hidden lg:block" aria-label="Conteúdo do curso">
        <div className="sticky top-6 flex max-h-[calc(100vh-3rem)] flex-col gap-3 overflow-y-auto rounded-xl border bg-card p-3">
          <Link
            href={coursePath(course.slug)}
            className="rounded-md px-2 py-1 text-sm font-semibold hover:text-primary"
          >
            {course.title}
          </Link>
          <CourseLessonsNav
            course={course}
            currentSlug={lesson.slug}
            idPrefix="lateral"
            renderLessonStatus={renderLessonStatus}
          />
        </div>
      </aside>
    </div>
  )
}

/**
 * Módulos e aulas do curso, com a aula atual marcada (`aria-current="page"`). Aparece duas
 * vezes na página (celular e desktop, uma escondida): `idPrefix` evita ids repetidos.
 */
export function CourseLessonsNav({
  course,
  currentSlug,
  idPrefix,
  renderLessonStatus,
}: {
  course: Course
  currentSlug: string
  idPrefix: string
  renderLessonStatus?: (lesson: string) => React.ReactNode
}) {
  return (
    <nav aria-label="Aulas do curso">
      <ol className="flex flex-col gap-4">
        {course.modules.map((module, index) => (
          <li key={module.slug} className="flex flex-col gap-1">
            <p
              id={`${idPrefix}-modulo-${module.slug}`}
              className="px-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
              <span className="sr-only">Módulo </span>
              {index + 1}. {module.title}
            </p>
            <ol aria-labelledby={`${idPrefix}-modulo-${module.slug}`} className="flex flex-col">
              {module.lessons.map((item) => {
                const current = item.slug === currentSlug
                return (
                  <li key={item.slug}>
                    <Link
                      href={lessonPath(course.slug, item.slug)}
                      aria-current={current ? "page" : undefined}
                      className={cn(
                        "flex items-center gap-2 rounded-md border-l-2 px-2 py-2 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50",
                        current
                          ? "border-primary bg-primary/10 font-medium text-foreground"
                          : "border-transparent text-muted-foreground hover:bg-accent hover:text-foreground",
                      )}
                    >
                      <span className="min-w-0 flex-1">{item.title}</span>
                      {renderLessonStatus?.(item.slug)}
                      {item.has_quiz && (
                        <>
                          <ListChecksIcon aria-hidden className="size-3.5 shrink-0" />
                          <span className="sr-only"> (tem quiz)</span>
                        </>
                      )}
                    </Link>
                  </li>
                )
              })}
            </ol>
          </li>
        ))}
      </ol>
    </nav>
  )
}

/** Anterior/próxima (a API já considera só as aulas públicas, na ordem do curso). */
export function LessonPager({
  course,
  previous,
  next,
}: {
  course: string
  previous: Lesson["previous"]
  next: Lesson["next"]
}) {
  if (!previous && !next) return null

  const card =
    "flex min-w-0 flex-1 flex-col gap-1 rounded-lg border bg-card p-4 transition-colors outline-none hover:border-primary/60 focus-visible:ring-[3px] focus-visible:ring-ring/50"

  return (
    <nav
      aria-label="Navegação entre aulas"
      className="flex flex-col gap-3 border-t pt-8 sm:flex-row"
    >
      {previous ? (
        <Link href={lessonPath(course, previous.slug)} rel="prev" className={card}>
          <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <ArrowLeftIcon aria-hidden className="size-3.5" />
            Aula anterior
          </span>
          <span className="font-medium">{previous.title}</span>
        </Link>
      ) : (
        <span className="hidden flex-1 sm:block" />
      )}
      {next ? (
        <Link
          href={lessonPath(course, next.slug)}
          rel="next"
          className={cn(card, "sm:items-end sm:text-right")}
        >
          <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            Próxima aula
            <ArrowRightIcon aria-hidden className="size-3.5" />
          </span>
          <span className="font-medium">{next.title}</span>
        </Link>
      ) : (
        <span className="hidden flex-1 sm:block" />
      )}
    </nav>
  )
}

export function LessonSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Carregando aula"
      className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
      <div className="flex flex-col gap-6">
        <Skeleton className="h-4 w-64" />
        <Skeleton className="h-10 w-3/4" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-5/6" />
        <Skeleton className="aspect-video w-full max-w-3xl" />
      </div>
      <Skeleton className="hidden h-96 rounded-xl lg:block" />
    </div>
  )
}
