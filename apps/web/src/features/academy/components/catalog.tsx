import Link from "next/link"
import { ChevronLeftIcon, ChevronRightIcon, SearchXIcon, ServerOffIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

import { ACADEMY_PATH } from "../lib/paths"
import { COURSE_LEVELS, type CourseLevel, type CourseList } from "../lib/schemas"
import { CourseCard } from "./course-card"
import { LEVELS } from "./course-meta"

/** URL do catálogo com os filtros (só os que a API aceita: `level` e `page`). */
export function catalogHref(level: CourseLevel | null, page = 1): string {
  const query = new URLSearchParams()
  if (level) query.set("level", level)
  if (page > 1) query.set("page", String(page))
  return query.size > 0 ? `${ACADEMY_PATH}?${query}` : ACADEMY_PATH
}

/**
 * Filtro por nível como links (funciona sem JavaScript, pode ser compartilhado e o "voltar" do
 * navegador funciona). O selecionado leva `aria-current`.
 */
export function LevelFilter({ current }: { current: CourseLevel | null }) {
  const options: { level: CourseLevel | null; label: string }[] = [
    { level: null, label: "Todos" },
    ...COURSE_LEVELS.map((level) => ({ level, label: LEVELS[level].label })),
  ]

  return (
    <nav aria-label="Filtrar por nível">
      <ul className="flex flex-wrap gap-2">
        {options.map(({ level, label }) => {
          const active = level === current
          return (
            <li key={label}>
              <Link
                href={catalogHref(level)}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "inline-flex h-9 items-center rounded-full border px-4 text-sm font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50",
                  active
                    ? "border-primary bg-primary text-primary-foreground"
                    : "bg-background text-muted-foreground hover:border-primary/60 hover:text-foreground",
                )}
              >
                {label}
              </Link>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

export function CatalogPagination({
  level,
  page,
  lastPage,
}: {
  level: CourseLevel | null
  page: number
  lastPage: number
}) {
  if (lastPage <= 1) return null

  return (
    <nav aria-label="Páginas do catálogo" className="flex items-center justify-center gap-3">
      {page > 1 ? (
        <Button asChild variant="outline" size="sm">
          <Link href={catalogHref(level, page - 1)} rel="prev">
            <ChevronLeftIcon aria-hidden />
            Anterior
          </Link>
        </Button>
      ) : (
        <Button variant="outline" size="sm" disabled>
          <ChevronLeftIcon aria-hidden />
          Anterior
        </Button>
      )}
      <p className="text-sm text-muted-foreground">
        Página <span className="font-medium text-foreground">{page}</span> de {lastPage}
      </p>
      {page < lastPage ? (
        <Button asChild variant="outline" size="sm">
          <Link href={catalogHref(level, page + 1)} rel="next">
            Próxima
            <ChevronRightIcon aria-hidden />
          </Link>
        </Button>
      ) : (
        <Button variant="outline" size="sm" disabled>
          Próxima
          <ChevronRightIcon aria-hidden />
        </Button>
      )}
    </nav>
  )
}

/** Lista de cursos de uma página do catálogo, com os estados vazios. */
export function CourseCatalog({
  list,
  level,
  renderCourseStatus,
}: {
  list: CourseList
  level: CourseLevel | null
  /** Marca do jogador em cada curso (Learning, etapa 2.4), montada pela página. */
  renderCourseStatus?: (course: string) => React.ReactNode
}) {
  const { data: courses, meta } = list

  if (courses.length === 0) {
    return meta.total === 0 && level === null ? (
      <CatalogEmpty
        title="Nenhum curso publicado ainda"
        description="Os primeiros cursos da Academy estão sendo preparados. Volte em breve."
      />
    ) : level !== null && meta.total === 0 ? (
      <CatalogEmpty
        title={`Nenhum curso de nível ${LEVELS[level].label.toLowerCase()}`}
        description="Experimente outro nível ou veja todos os cursos."
        action={{ href: catalogHref(null), label: "Ver todos os cursos" }}
      />
    ) : (
      <CatalogEmpty
        title="Esta página não existe"
        description="O catálogo tem menos páginas do que o endereço indica."
        action={{ href: catalogHref(level), label: "Voltar à primeira página" }}
      />
    )
  }

  return (
    <div className="flex flex-col gap-8">
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {meta.total === 1 ? "1 curso" : `${meta.total} cursos`}
        {level !== null && ` de nível ${LEVELS[level].label.toLowerCase()}`}
      </p>
      <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {courses.map((course) => (
          <li key={course.slug} className="flex">
            <CourseCard course={course} status={renderCourseStatus?.(course.slug)} />
          </li>
        ))}
      </ul>
      <CatalogPagination level={level} page={meta.current_page} lastPage={meta.last_page} />
    </div>
  )
}

function CatalogEmpty({
  title,
  description,
  action,
}: {
  title: string
  description: string
  action?: { href: string; label: string }
}) {
  return (
    <Empty className="border">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <SearchXIcon aria-hidden />
        </EmptyMedia>
        <EmptyTitle>{title}</EmptyTitle>
        <EmptyDescription>{description}</EmptyDescription>
      </EmptyHeader>
      {action && (
        <EmptyContent>
          <Button asChild variant="outline">
            <Link href={action.href}>{action.label}</Link>
          </Button>
        </EmptyContent>
      )}
    </Empty>
  )
}

/** API fora do ar, lenta ou com resposta inesperada: mensagem para o jogador, sem detalhes técnicos. */
export function AcademyUnavailable({ retryHref }: { retryHref: string }) {
  return (
    <Empty className="border border-dashed">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <ServerOffIcon aria-hidden />
        </EmptyMedia>
        <EmptyTitle>A Academy está temporariamente indisponível</EmptyTitle>
        <EmptyDescription>
          Não conseguimos carregar o conteúdo agora. Aguarde alguns instantes e tente de novo.
        </EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <Button asChild>
          {/* Link comum (não <Link>): recarrega a página e refaz a leitura no servidor. */}
          <a href={retryHref}>Tentar novamente</a>
        </Button>
      </EmptyContent>
    </Empty>
  )
}

export function CatalogSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      aria-label="Carregando cursos"
      className="flex flex-col gap-8"
    >
      <Skeleton className="h-4 w-24" />
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="flex flex-col overflow-hidden rounded-xl border">
            <Skeleton className="aspect-video rounded-none" />
            <div className="flex flex-col gap-3 p-5">
              <Skeleton className="h-5 w-3/4" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-2/3" />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
