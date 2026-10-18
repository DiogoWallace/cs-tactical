import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { enrollmentSummary } from "../test/fixtures"
import {
  AcademyDashboardCard,
  AcademyDashboardCardSkeleton,
  academyTotals,
  pickCourseToContinue,
} from "./academy-dashboard-card"

const TZ = "America/Sao_Paulo"

const archived = enrollmentSummary({
  course: { slug: "antigo", title: "Curso antigo", status: "archived" },
  completed_at: "2026-10-01T10:00:00Z",
  last_activity_at: "2026-11-05T10:00:00Z",
  progress: { completed_lessons: 4, total_lessons: null, percent: null },
  next_lesson: null,
})

const completedUpToDate = enrollmentSummary({
  course: { slug: "mira", title: "Mira", status: "published" },
  completed_at: "2026-11-01T10:00:00Z",
  last_activity_at: "2026-11-04T10:00:00Z",
  progress: { completed_lessons: 2, total_lessons: 2, percent: 100 },
  next_lesson: null,
})

function card() {
  return screen.getByRole("region", { name: "Academy" })
}

describe("pickCourseToContinue", () => {
  it("keeps the API order (latest activity first) and skips archived or finished courses", () => {
    const inProgress = enrollmentSummary()
    expect(pickCourseToContinue([archived, completedUpToDate, inProgress])).toBe(inProgress)
  })

  it("offers a completed course that gained a new lesson, in API order", () => {
    const newLesson = enrollmentSummary({
      completed_at: "2026-11-01T10:00:00Z",
      next_lesson: { slug: "aula-nova", title: "Aula nova" },
    })
    const older = enrollmentSummary({ course: { ...enrollmentSummary().course, slug: "outro" } })
    expect(pickCourseToContinue([newLesson, older])).toBe(newLesson)
  })

  it("returns null when nothing is pending", () => {
    expect(pickCourseToContinue([])).toBeNull()
    expect(pickCourseToContinue([archived, completedUpToDate])).toBeNull()
  })
})

describe("academyTotals", () => {
  it("counts every enrollment, completed courses and recorded completions", () => {
    expect(academyTotals([enrollmentSummary(), archived, completedUpToDate])).toEqual({
      enrolled: 3,
      completed: 2,
      completedLessons: 7,
    })
  })
})

describe("AcademyDashboardCard", () => {
  it("invites a player without enrollments to explore the catalog", () => {
    render(<AcademyDashboardCard load={{ kind: "player", enrollments: [] }} timeZone={TZ} />)

    expect(within(card()).getByText("Você ainda não está em nenhum curso")).toBeInTheDocument()
    expect(within(card()).getByRole("link", { name: "Explorar cursos" })).toHaveAttribute(
      "href",
      "/academy",
    )
    expect(within(card()).queryByRole("link", { name: /Continuar estudando/ })).toBeNull()
    expect(within(card()).queryByRole("term")).toBeNull()
  })

  it("shows the totals and where to continue for a course in progress", () => {
    render(
      <AcademyDashboardCard
        load={{ kind: "player", enrollments: [enrollmentSummary(), archived] }}
        timeZone={TZ}
      />,
    )

    const region = card()
    const stats = within(region)
      .getAllByRole("term")
      .map((term) => ({
        label: term.textContent,
        value: term.nextElementSibling?.textContent,
      }))
    expect(stats).toEqual([
      { label: "Cursos matriculados", value: "2" },
      { label: "Curso concluído", value: "1" },
      { label: "Aulas concluídas", value: "5" },
    ])

    expect(within(region).getByText("Continue de onde parou")).toBeInTheDocument()
    expect(within(region).getByText("Fundamentos do CS2")).toBeInTheDocument()
    expect(within(region).getByText("Próxima aula: Peek seguro")).toBeInTheDocument()
    expect(within(region).getByRole("progressbar", { name: "Progresso do curso" })).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )
    // A data é da última atividade registrada (no fuso do perfil), nunca "último acesso".
    expect(
      within(region).getByText("Última atividade registrada em 2 de nov. de 2026"),
    ).toBeInTheDocument()

    expect(within(region).getByRole("link", { name: "Continuar estudando" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2/aulas/peek-seguro",
    )
    expect(within(region).getByRole("link", { name: "Ver meu progresso" })).toHaveAttribute(
      "href",
      "/painel/academy",
    )
    expect(within(region).getByRole("link", { name: "Explorar cursos" })).toHaveAttribute(
      "href",
      "/academy",
    )
  })

  it("labels a new lesson in a completed course", () => {
    render(
      <AcademyDashboardCard
        load={{
          kind: "player",
          enrollments: [
            enrollmentSummary({
              completed_at: "2026-11-01T10:00:00Z",
              progress: { completed_lessons: 3, total_lessons: 4, percent: 75 },
              next_lesson: { slug: "aula-nova", title: "Aula nova" },
            }),
          ],
        }}
        timeZone={TZ}
      />,
    )

    expect(within(card()).getByText("Aula nova no curso")).toBeInTheDocument()
    expect(within(card()).getByRole("link", { name: "Continuar estudando" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2/aulas/aula-nova",
    )
  })

  it("says the player is up to date when every course is completed", () => {
    render(
      <AcademyDashboardCard
        load={{ kind: "player", enrollments: [completedUpToDate, archived] }}
        timeZone={TZ}
      />,
    )

    expect(
      within(card()).getByText("Você está em dia: nenhuma aula pendente nos seus cursos."),
    ).toBeInTheDocument()
    expect(within(card()).queryByRole("link", { name: "Continuar estudando" })).toBeNull()
    expect(within(card()).queryByRole("progressbar")).toBeNull()
    expect(within(card()).getByRole("link", { name: "Ver meu progresso" })).toBeInTheDocument()
  })

  it("reports an API failure without inventing numbers", () => {
    render(<AcademyDashboardCard load={{ kind: "unavailable" }} timeZone={TZ} />)

    expect(within(card()).getByRole("alert")).toHaveTextContent(
      "Não foi possível carregar seu progresso agora.",
    )
    expect(within(card()).queryByRole("term")).toBeNull()
    expect(within(card()).getByRole("link", { name: "Tentar novamente" })).toHaveAttribute(
      "href",
      "/painel",
    )
    expect(within(card()).getByRole("link", { name: "Explorar cursos" })).toBeInTheDocument()
  })

  it("asks to sign in again when the session expired, coming back to the dashboard", () => {
    render(<AcademyDashboardCard load={{ kind: "anonymous" }} timeZone={TZ} />)

    expect(within(card()).getByRole("alert")).toHaveTextContent("Sua sessão expirou.")
    expect(within(card()).getByRole("link", { name: "Entrar novamente" })).toHaveAttribute(
      "href",
      "/login?next=%2Fpainel",
    )
    expect(within(card()).queryByRole("term")).toBeNull()
  })

  it("announces the loading state", () => {
    render(<AcademyDashboardCardSkeleton />)

    expect(
      screen.getByRole("status", { name: "Carregando seu progresso na Academy" }),
    ).toHaveAttribute("aria-busy", "true")
  })
})
