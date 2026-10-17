import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { SafeMarkdown } from "./safe-markdown"

/**
 * O renderizador é a segunda barreira: o texto já chega validado (importador e Zod). Aqui o
 * Markdown é passado direto, para provar que nem um conteúdo malicioso vira HTML executável.
 */
describe("SafeMarkdown", () => {
  it("renders the supported Markdown elements", () => {
    const { container } = render(
      <SafeMarkdown
        text={
          "## Título\n\nTexto com **negrito**, *itálico* e `código`.\n\n- um\n- dois\n\n1. primeiro\n\n> citação\n\n```\nbind\n```\n\n---"
        }
      />,
    )

    expect(screen.getByRole("heading", { level: 2, name: "Título" })).toBeInTheDocument()
    expect(container.querySelector("strong")).toHaveTextContent("negrito")
    expect(container.querySelector("em")).toHaveTextContent("itálico")
    expect(container.querySelector("ul")?.children).toHaveLength(2)
    expect(container.querySelector("ol")).toBeInTheDocument()
    expect(container.querySelector("blockquote")).toHaveTextContent("citação")
    expect(container.querySelector("pre code")).toHaveTextContent("bind")
    expect(container.querySelector("hr")).toBeInTheDocument()
  })

  it("never turns raw HTML into elements", () => {
    const { container } = render(
      <SafeMarkdown
        text={
          'Antes <script>alert(1)</script> <img src=x onerror="alert(1)"> <iframe src="https://evil.example"></iframe> depois'
        }
      />,
    )

    expect(container.querySelector("script, img, iframe, [onerror]")).toBeNull()
    expect(container.innerHTML).not.toContain("<script")
    expect(container).toHaveTextContent("Antes")
    expect(container).toHaveTextContent("depois")
  })

  it("does not render Markdown images", () => {
    const { container } = render(<SafeMarkdown text="![rastreador](https://evil.example/x.png)" />)
    expect(container.querySelector("img")).toBeNull()
  })

  it("turns links with forbidden schemes into plain text", () => {
    const { container } = render(
      <SafeMarkdown text="[a](javascript:alert(1)) [b](http://example.com) [c](//evil.example) [d](data:text/html,x)" />,
    )

    expect(container.querySelector("a")).toBeNull()
    expect(container).toHaveTextContent("a b c d")
  })

  it("opens external links in a new tab without opener or referrer, and says so", () => {
    render(<SafeMarkdown text="[guia](https://example.com) e [catálogo](/academy)" />)

    const external = screen.getByRole("link", { name: /guia/ })
    expect(external).toHaveAttribute("href", "https://example.com")
    expect(external).toHaveAttribute("target", "_blank")
    expect(external).toHaveAttribute("rel", "noopener noreferrer")
    expect(external).toHaveTextContent("(abre em nova aba)")

    const internal = screen.getByRole("link", { name: "catálogo" })
    expect(internal).toHaveAttribute("href", "/academy")
    expect(internal).not.toHaveAttribute("target")
  })

  it("keeps headings below the lesson title (h1 becomes h2)", () => {
    render(<SafeMarkdown text={"# Escapou\n\n### Subtítulo"} />)

    expect(screen.queryByRole("heading", { level: 1 })).toBeNull()
    expect(screen.getByRole("heading", { level: 2, name: "Escapou" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 3, name: "Subtítulo" })).toBeInTheDocument()
  })

  it("only renders inline elements in inline mode", () => {
    const { container } = render(
      <SafeMarkdown inline text={"## título\n\n- item com **negrito**"} />,
    )

    expect(container.querySelector("h2, ul, li")).toBeNull()
    expect(container.querySelector("strong")).toHaveTextContent("negrito")
  })
})
