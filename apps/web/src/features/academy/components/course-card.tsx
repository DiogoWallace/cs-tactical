import Link from "next/link"
import { ArrowRightIcon } from "lucide-react"

import { coursePath } from "../lib/paths"
import type { CourseSummary } from "../lib/schemas"
import { CourseCover } from "./course-cover"
import { CourseStats, LevelBadge } from "./course-meta"

/**
 * Curso no catálogo. O cartão inteiro é clicável pelo link do título (um único link por
 * cartão: o leitor de tela não ouve "Ver curso" repetido).
 */
export function CourseCard({
  course,
  status,
}: {
  course: CourseSummary
  status?: React.ReactNode
}) {
  return (
    <article className="group relative flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm transition-colors focus-within:border-primary/60 hover:border-primary/60">
      <div className="relative">
        <CourseCover url={course.cover_image_url} />
        <LevelBadge level={course.level} className="absolute top-3 left-3" />
      </div>
      <div className="flex flex-1 flex-col gap-3 p-5">
        <h2 className="text-lg leading-snug font-semibold tracking-tight">
          <Link
            href={coursePath(course.slug)}
            className="outline-none after:absolute after:inset-0 after:content-[''] focus-visible:underline"
          >
            {course.title}
          </Link>
        </h2>
        <p className="line-clamp-3 text-sm text-muted-foreground">{course.summary}</p>
        {status}
        <div className="mt-auto flex items-end justify-between gap-3 pt-2">
          <CourseStats
            moduleCount={course.module_count}
            lessonCount={course.lesson_count}
            minutes={course.estimated_minutes}
          />
          <ArrowRightIcon
            aria-hidden
            className="size-5 shrink-0 text-primary transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
          />
        </div>
      </div>
    </article>
  )
}
