import { describe, expect, it } from "vitest"

import { isAllowedDestination, isSafeBlockMarkdown, isSafeInlineMarkdown } from "./markdown-rules"

/** Mesmos casos de LessonContentSchemaTest (PHP): as duas implementações decidem igual. */
describe("block Markdown rules", () => {
  it.each([
    ["script", "Olá <script>alert(1)</script>"],
    ["fechamento", "texto </div>"],
    ["comentário", "<!-- escondido -->"],
    ["autolink", "<https://example.com>"],
    ["imagem markdown", "![x](https://example.com/x.png)"],
    ["link javascript", "[clique](javascript:alert(1))"],
    ["link data", "[x](data:text/html,oi)"],
    ["link http", "[x](http://example.com)"],
    ["link protocolo relativo", "[x](//evil.example)"],
    ["link barra invertida", "[x](/\\evil.example)"],
    ["definição de referência", "[x][r]\n\n[r]: javascript:alert(1)"],
    ["h1 atx", "# Título principal"],
    ["h1 setext", "Título\n==="],
  ])("rejects %s", (_name, text) => {
    expect(isSafeBlockMarkdown(text)).toBe(false)
  })

  it.each([
    ["menor que com espaço", "Se a < b, use a."],
    ["título h2", "## Título"],
    ["# dentro de bloco de código", "```\n# comentário\n```"],
    [
      "links permitidos",
      "[interno](/academy), [externo](https://example.com), [e-mail](mailto:a@b.c) e [âncora](#topo)",
    ],
  ])("accepts %s", (_name, text) => {
    expect(isSafeBlockMarkdown(text)).toBe(true)
  })
})

describe("inline Markdown rules", () => {
  it("accepts emphasis, code and links", () => {
    expect(isSafeInlineMarkdown("Use **D** e `bind` ([guia](https://example.com)).")).toBe(true)
  })

  it.each([
    ["lista", "- item"],
    ["título", "## título"],
    ["citação", "> citação"],
    ["código em bloco", "```\nx\n```"],
    ["HTML", "<b>x</b>"],
  ])("rejects %s", (_name, text) => {
    expect(isSafeInlineMarkdown(text)).toBe(false)
  })
})

describe("link destinations", () => {
  it.each(["https://example.com", "mailto:a@b.c", "#topo", "/academy"])("allows %s", (url) => {
    expect(isAllowedDestination(url)).toBe(true)
  })

  it.each([
    "http://example.com",
    "javascript:alert(1)",
    "//evil.example",
    "/\\evil.example",
    "data:x",
    "vbscript:x",
    "",
  ])("blocks %s", (url) => {
    expect(isAllowedDestination(url)).toBe(false)
  })
})
