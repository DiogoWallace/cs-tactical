import type { Metadata } from "next"
import { Suspense } from "react"
import { CrosshairIcon } from "lucide-react"

import { catalogFilters, getCourses } from "@/features/academy/api/catalog"
import {
  AcademyUnavailable,
  catalogHref,
  CatalogSkeleton,
  CourseCatalog,
  LevelFilter,
} from "@/features/academy/components/catalog"
import { ACADEMY_PATH } from "@/features/academy/lib/paths"
import { getMyEnrollments } from "@/features/learning/api/progress"
import { EnrollmentBadge } from "@/features/learning/components/enrollment-badge"

const DESCRIPTION =
  "Cursos de Counter-Strike 2 com método: movimentação, mira, utilitários e tomada de decisão, do iniciante ao avançado."

export const metadata: Metadata = {
  title: "Academy",
  description: DESCRIPTION,
  alternates: { canonical: ACADEMY_PATH },
  openGraph: {
    type: "website",
    title: "Academy · CS Tactical",
    description: DESCRIPTION,
    url: ACADEMY_PATH,
  },
}

/** Catálogo público. O cabeçalho é estático; a lista é lida da API a cada requisição. */
export default function AcademyPage({ searchParams }: PageProps<"/academy">) {
  return (
    <div className="flex flex-col">
      <section className="relative overflow-hidden border-b">
        <div
          aria-hidden
          className="absolute inset-0 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] mask-[radial-gradient(ellipse_at_top_right,black_10%,transparent_70%)] bg-size-[32px_32px]"
        />
        <div className="relative mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-14 sm:px-6 sm:py-20">
          <p className="inline-flex items-center gap-2 text-sm font-medium tracking-widest text-primary uppercase">
            <CrosshairIcon aria-hidden className="size-4" />
            CS Tactical Academy
          </p>
          <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
            Aprenda CS2 com método, uma aula de cada vez.
          </h1>
          <p className="max-w-2xl text-lg text-muted-foreground">
            Cursos organizados em módulos curtos, com texto, imagens, vídeos e quizzes para fixar o
            que importa em partida.
          </p>
        </div>
      </section>

      <section
        aria-label="Cursos"
        className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-10 sm:px-6"
      >
        <Suspense fallback={<CatalogSkeleton />}>
          <CatalogResults searchParams={searchParams} />
        </Suspense>
      </section>
    </div>
  )
}

async function CatalogResults({
  searchParams,
}: {
  searchParams: PageProps<"/academy">["searchParams"]
}) {
  const { level, page } = catalogFilters(await searchParams)
  // Em paralelo: o catálogo (igual para todos) e as matrículas do jogador (só com sessão).
  const [result, mine] = await Promise.all([getCourses(level, page), getMyEnrollments()])
  const enrollments = new Map(
    mine.kind === "player" ? mine.enrollments.map((item) => [item.course.slug, item]) : [],
  )

  return (
    <>
      <LevelFilter current={level} />
      {result.status === "ok" ? (
        <CourseCatalog
          list={result.data}
          level={level}
          renderCourseStatus={(course) => {
            const enrollment = enrollments.get(course)
            return enrollment ? <EnrollmentBadge enrollment={enrollment} /> : null
          }}
        />
      ) : (
        <AcademyUnavailable retryHref={catalogHref(level, page)} />
      )}
    </>
  )
}
