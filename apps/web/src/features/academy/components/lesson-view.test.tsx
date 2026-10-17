import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { course, lesson } from "../test/fixtures"
import { LessonPager, LessonSkeleton, LessonView, lessonPosition } from "./lesson-view"

describe("lessonPosition", () => {
  it("counts the lesson across modules", () => {
    expect(lessonPosition(course(), "peek-seguro")).toEqual({ number: 2, total: 3 })
    expect(lessonPosition(course(), "crosshair")).toEqual({ number: 3, total: 3 })
    expect(lessonPosition(course(), "nao-existe")).toEqual({ number: 0, total: 3 })
  })
})

describe("LessonView", () => {
  it("identifies the course, module and the lesson position", () => {
    render(<LessonView course={course()} lesson={lesson()} />)

    expect(screen.getByRole("heading", { level: 1, name: "Peek seguro" })).toBeInTheDocument()
    expect(screen.getByText("Aula 2 de 3")).toBeInTheDocument()
    expect(screen.getByText("Mostre só o necessário.")).toBeInTheDocument()

    const trail = screen.getByRole("navigation", { name: "Trilha" })
    expect(within(trail).getByRole("link", { name: "Academy" })).toHaveAttribute("href", "/academy")
    expect(within(trail).getByRole("link", { name: "Fundamentos do CS2" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2",
    )
    expect(trail).toHaveTextContent("Movimentação")
  })

  it("renders the lesson blocks inside the article", () => {
    render(<LessonView course={course()} lesson={lesson()} />)

    const article = screen.getByRole("article")
    expect(within(article).getByRole("heading", { level: 2, name: "Ângulos" })).toBeInTheDocument()
  })

  it("marks the current lesson in both course navigations (mobile and desktop)", () => {
    render(<LessonView course={course()} lesson={lesson()} />)

    const navs = screen.getAllByRole("navigation", { name: "Aulas do curso", hidden: true })
    expect(navs).toHaveLength(2)
    for (const nav of navs) {
      const current = within(nav).getByRole("link", { name: "Peek seguro", hidden: true })
      expect(current).toHaveAttribute("aria-current", "page")
      // O jsdom junta os spans sem o espaço que o navegador mantém.
      const withQuiz = within(nav).getByRole("link", {
        name: /^Counter-strafe ?\(tem quiz\)$/,
        hidden: true,
      })
      expect(withQuiz).not.toHaveAttribute("aria-current")
    }
    // Ids das duas listas não se repetem (aria-labelledby).
    const ids = Array.from(document.querySelectorAll("[id]"), (node) => node.id)
    expect(new Set(ids).size).toBe(ids.length)
  })

  it("keeps the mobile course list collapsed by default", () => {
    const { container } = render(<LessonView course={course()} lesson={lesson()} />)
    const details = container.querySelector("details")
    expect(details).not.toHaveAttribute("open")
    expect(details?.querySelector("summary")).toHaveTextContent("Aulas do curso")
  })

  it("says when a lesson has no renderable content", () => {
    render(
      <LessonView course={course()} lesson={lesson({ content: { version: 1, blocks: [] } })} />,
    )
    expect(screen.getByText("O conteúdo desta aula ainda não está disponível.")).toBeInTheDocument()
  })

  it("announces the loading state", () => {
    render(<LessonSkeleton />)
    expect(screen.getByRole("status", { name: "Carregando aula" })).toBeInTheDocument()
  })
})

describe("LessonPager", () => {
  it("links the previous and next public lessons given by the API", () => {
    render(
      <LessonPager
        course="fundamentos-cs2"
        previous={{ slug: "counter-strafe", title: "Counter-strafe" }}
        next={{ slug: "crosshair", title: "Crosshair" }}
      />,
    )

    const nav = screen.getByRole("navigation", { name: "Navegação entre aulas" })
    const previous = within(nav).getByRole("link", { name: /Aula anterior/ })
    expect(previous).toHaveAttribute("href", "/academy/cursos/fundamentos-cs2/aulas/counter-strafe")
    expect(previous).toHaveAttribute("rel", "prev")
    expect(previous).toHaveTextContent("Counter-strafe")

    const next = within(nav).getByRole("link", { name: /Próxima aula/ })
    expect(next).toHaveAttribute("href", "/academy/cursos/fundamentos-cs2/aulas/crosshair")
    expect(next).toHaveAttribute("rel", "next")
  })

  it("shows only the existing direction at the ends", () => {
    render(<LessonPager course="c" previous={null} next={{ slug: "b", title: "B" }} />)
    expect(screen.queryByRole("link", { name: /Aula anterior/ })).toBeNull()
    expect(screen.getByRole("link", { name: /Próxima aula/ })).toBeInTheDocument()
  })

  it("renders nothing for a single-lesson course", () => {
    const { container } = render(<LessonPager course="c" previous={null} next={null} />)
    expect(container).toBeEmptyDOMElement()
  })
})
