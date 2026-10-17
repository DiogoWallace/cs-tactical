import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { course } from "../test/fixtures"
import { CourseOverview, CourseOverviewSkeleton, courseTotals } from "./course-overview"

describe("courseTotals", () => {
  it("counts the visible modules, lessons and minutes", () => {
    expect(courseTotals(course())).toEqual({
      moduleCount: 2,
      lessonCount: 3,
      minutes: 20,
      firstLesson: {
        slug: "counter-strafe",
        title: "Counter-strafe",
        estimated_minutes: 8,
        has_quiz: true,
      },
    })
  })

  it("has no first lesson and no duration for an empty course", () => {
    expect(courseTotals(course({ modules: [] }))).toMatchObject({
      lessonCount: 0,
      minutes: null,
      firstLesson: null,
    })
  })
})

describe("CourseOverview", () => {
  it("shows the course identity and starts at the first available lesson", () => {
    render(<CourseOverview course={course()} />)

    expect(
      screen.getByRole("heading", { level: 1, name: "Fundamentos do CS2" }),
    ).toBeInTheDocument()
    expect(screen.getByText("Movimentação e mira para quem está começando.")).toBeInTheDocument()
    expect(screen.getByText("Iniciante")).toBeInTheDocument()
    expect(screen.getByText("2 módulos")).toBeInTheDocument()
    expect(screen.getByText("3 aulas")).toBeInTheDocument()
    expect(screen.getByText("20 min")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Começar pela primeira aula" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2/aulas/counter-strafe",
    )
    expect(screen.getByRole("link", { name: "Todos os cursos" })).toHaveAttribute(
      "href",
      "/academy",
    )
  })

  it("lists modules and lessons in order, numbering lessons across the course", () => {
    render(<CourseOverview course={course()} />)

    const content = screen.getByRole("region", { name: "Conteúdo do curso" })
    const modules = within(content).getAllByRole("heading", { level: 3 })
    expect(modules.map((heading) => heading.textContent)).toEqual([
      "Módulo 1: Movimentação",
      "Módulo 2: Mira",
    ])

    const lessons = within(content).getAllByRole("link")
    expect(lessons.map((link) => link.getAttribute("href"))).toEqual([
      "/academy/cursos/fundamentos-cs2/aulas/counter-strafe",
      "/academy/cursos/fundamentos-cs2/aulas/peek-seguro",
      "/academy/cursos/fundamentos-cs2/aulas/crosshair",
    ])
    expect(lessons[0]).toHaveTextContent("Aula 1")
    expect(lessons[0]).toHaveTextContent("Quiz")
    expect(lessons[0]).toHaveTextContent("8 min")
    expect(lessons[1]).not.toHaveTextContent("Quiz")
    expect(lessons[2]).toHaveTextContent("Aula 3")
  })

  it("has no progress, enrollment or completion yet (Learning, step 2.4)", () => {
    render(<CourseOverview course={course()} />)
    expect(document.body).not.toHaveTextContent(/matricul|progresso|conclu/i)
  })

  it("handles a course without visible lessons", () => {
    render(<CourseOverview course={course({ modules: [] })} />)

    expect(screen.queryByRole("link", { name: "Começar pela primeira aula" })).toBeNull()
    expect(
      screen.getByText("As aulas deste curso ainda estão sendo preparadas."),
    ).toBeInTheDocument()
  })

  it("announces the loading state", () => {
    render(<CourseOverviewSkeleton />)
    expect(screen.getByRole("status", { name: "Carregando curso" })).toBeInTheDocument()
  })
})
