import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { courseList, courseSummary } from "../test/fixtures"
import {
  AcademyUnavailable,
  catalogHref,
  CatalogPagination,
  CatalogSkeleton,
  CourseCatalog,
  LevelFilter,
} from "./catalog"

describe("catalogHref", () => {
  it("only carries the filters the API supports", () => {
    expect(catalogHref(null)).toBe("/academy")
    expect(catalogHref("advanced")).toBe("/academy?level=advanced")
    expect(catalogHref("beginner", 3)).toBe("/academy?level=beginner&page=3")
    expect(catalogHref(null, 1)).toBe("/academy")
  })
})

describe("CourseCatalog", () => {
  it("lists courses with cover, level, numbers and a link to the course", () => {
    render(
      <CourseCatalog
        list={courseList([
          courseSummary(),
          courseSummary({
            slug: "taticas",
            title: "Táticas",
            level: "advanced",
            cover_image_url: null,
            estimated_minutes: null,
            module_count: 1,
            lesson_count: 1,
          }),
        ])}
        level={null}
      />,
    )

    expect(screen.getByText("2 cursos")).toBeInTheDocument()
    const cards = screen.getAllByRole("article")
    expect(cards).toHaveLength(2)

    const first = within(cards[0]!)
    expect(first.getByRole("heading", { level: 2, name: "Fundamentos do CS2" })).toBeInTheDocument()
    expect(first.getByRole("link", { name: "Fundamentos do CS2" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2",
    )
    expect(first.getByText("Iniciante")).toBeInTheDocument()
    expect(first.getByText("2 módulos")).toBeInTheDocument()
    expect(first.getByText("3 aulas")).toBeInTheDocument()
    expect(first.getByText("1 h 35 min")).toBeInTheDocument()
    // A capa é decorativa: o título já está em texto.
    expect(cards[0]!.querySelector("img")).toHaveAttribute("alt", "")

    const second = within(cards[1]!)
    expect(second.getByText("Avançado")).toBeInTheDocument()
    expect(second.getByText("1 módulo")).toBeInTheDocument()
    expect(second.getByText("1 aula")).toBeInTheDocument()
    expect(second.queryByText(/min/)).toBeNull()
    expect(cards[1]!.querySelector("img")).toBeNull()
  })

  it("says when nothing is published yet", () => {
    render(<CourseCatalog list={courseList([])} level={null} />)
    expect(screen.getByText("Nenhum curso publicado ainda")).toBeInTheDocument()
    expect(screen.queryByRole("article")).toBeNull()
  })

  it("explains an empty filter and links back to all courses", () => {
    render(<CourseCatalog list={courseList([])} level="intermediate" />)

    expect(screen.getByText("Nenhum curso de nível intermediário")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Ver todos os cursos" })).toHaveAttribute(
      "href",
      "/academy",
    )
  })

  it("handles a page past the end", () => {
    render(
      <CourseCatalog
        list={courseList([], { current_page: 9, last_page: 2, total: 25 })}
        level="beginner"
      />,
    )

    expect(screen.getByText("Esta página não existe")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Voltar à primeira página" })).toHaveAttribute(
      "href",
      "/academy?level=beginner",
    )
  })
})

describe("LevelFilter", () => {
  it("links every level supported by the API and marks the current one", () => {
    render(<LevelFilter current="advanced" />)

    const nav = screen.getByRole("navigation", { name: "Filtrar por nível" })
    const links = within(nav).getAllByRole("link")
    expect(links.map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
      ["Todos", "/academy"],
      ["Iniciante", "/academy?level=beginner"],
      ["Intermediário", "/academy?level=intermediate"],
      ["Avançado", "/academy?level=advanced"],
    ])
    expect(within(nav).getByRole("link", { name: "Avançado" })).toHaveAttribute(
      "aria-current",
      "page",
    )
    expect(within(nav).getByRole("link", { name: "Todos" })).not.toHaveAttribute("aria-current")
  })
})

describe("CatalogPagination", () => {
  it("is hidden when there is only one page", () => {
    const { container } = render(<CatalogPagination level={null} page={1} lastPage={1} />)
    expect(container).toBeEmptyDOMElement()
  })

  it("links the neighbour pages and keeps the level", () => {
    render(<CatalogPagination level="beginner" page={2} lastPage={3} />)

    const nav = screen.getByRole("navigation", { name: "Páginas do catálogo" })
    expect(nav).toHaveTextContent("Página 2 de 3")
    expect(within(nav).getByRole("link", { name: "Anterior" })).toHaveAttribute(
      "href",
      "/academy?level=beginner",
    )
    expect(within(nav).getByRole("link", { name: "Próxima" })).toHaveAttribute(
      "href",
      "/academy?level=beginner&page=3",
    )
  })

  it("disables the missing direction on the first and last pages", () => {
    const { rerender } = render(<CatalogPagination level={null} page={1} lastPage={2} />)
    expect(screen.getByRole("button", { name: "Anterior" })).toBeDisabled()
    expect(screen.getByRole("link", { name: "Próxima" })).toHaveAttribute("href", "/academy?page=2")

    rerender(<CatalogPagination level={null} page={2} lastPage={2} />)
    expect(screen.getByRole("link", { name: "Anterior" })).toHaveAttribute("href", "/academy")
    expect(screen.getByRole("button", { name: "Próxima" })).toBeDisabled()
  })
})

describe("loading and error states", () => {
  it("announces the loading state", () => {
    render(<CatalogSkeleton />)
    expect(screen.getByRole("status", { name: "Carregando cursos" })).toHaveAttribute(
      "aria-busy",
      "true",
    )
  })

  it("explains an unavailable API without technical details and offers a retry", () => {
    render(<AcademyUnavailable retryHref="/academy?level=advanced" />)

    expect(screen.getByText("A Academy está temporariamente indisponível")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Tentar novamente" })).toHaveAttribute(
      "href",
      "/academy?level=advanced",
    )
    expect(document.body).not.toHaveTextContent(/api|500|503|erro interno|exception/i)
  })
})
