import { readFileSync } from "node:fs"
import { resolve } from "node:path"

import { describe, expect, it } from "vitest"

import { blockSchema, courseListSchema, courseSchema, lessonSchema, parseBlocks } from "./schemas"

/**
 * Casos compartilhados com o PHP (LessonContentSchemaTest): content/academy/_fixtures/blocks-v1.json.
 * O caminho relativo vale no host, na CI e no container (`./content` montado em /content).
 *
 * `block` é o formato do arquivo e `data` o do banco. A API entrega `data` igual para markdown,
 * callout e vídeo; imagem e quiz_ref chegam resolvidos (URL e resumo), como em `resolve()`.
 */
type Fixture = { name: string; block: Record<string, unknown>; data?: Record<string, unknown> }
const fixtures = JSON.parse(
  readFileSync(resolve(process.cwd(), "../../content/academy/_fixtures/blocks-v1.json"), "utf8"),
) as { version: number; valid: Fixture[]; invalid: Fixture[] }

/** O que a API (LessonResource) faz com os dados do banco. */
function resolveData(type: string, data: Record<string, unknown>): Record<string, unknown> {
  if (type === "image") {
    return {
      url: `/api/v1/academy/media/${String(data.media)}.png`,
      alt: data.alt,
      caption: data.caption,
      width: 640,
      height: 360,
    }
  }
  if (type === "quiz_ref") {
    return {
      quiz: data.quiz,
      title: "Teste rápido",
      question_count: 2,
      max_attempts: 3,
      pass_percent: 70,
    }
  }
  return data
}

describe("shared block fixtures (blocks-v1.json)", () => {
  it("is the version this mirror implements", () => {
    expect(fixtures.version).toBe(1)
    expect(fixtures.valid.length).toBeGreaterThan(0)
  })

  it.each(fixtures.valid.map((fixture) => [fixture.name, fixture] as const))(
    "accepts the valid case: %s",
    (_name, fixture) => {
      const type = String(fixture.block.type)
      const result = blockSchema.safeParse({ type, data: resolveData(type, fixture.data ?? {}) })
      expect(result.success).toBe(true)
    },
  )

  /**
   * Casos inválidos que a API pode ter de entregar em formato de dados. Ficam de fora os que só
   * existem no arquivo (URL de vídeo, `src`/`decorative` da imagem, quiz de outra aula): o
   * importador recusa, e esses campos nem existem na resposta da API.
   */
  const SERVER_ONLY = new Set([
    "vídeo de outro domínio",
    "imagem sem alt",
    "imagem decorativa com alt",
    "imagem inexistente",
    "quiz_ref de outro quiz",
  ])

  function asData(block: Record<string, unknown>): {
    type: unknown
    data: Record<string, unknown>
  } {
    const { type, ...fields } = block
    if (type === "callout") return { type, data: { title: null, ...fields } }
    if (type === "video") {
      // A URL do arquivo vira `video_id` na importação; o resto do bloco segue igual.
      const rest = Object.fromEntries(Object.entries(fields).filter(([key]) => key !== "url"))
      return {
        type,
        data: { provider: "youtube", video_id: "AbCdEfGhIjK", start_seconds: null, ...rest },
      }
    }
    return { type, data: fields }
  }

  it.each(
    fixtures.invalid
      .filter((fixture) => !SERVER_ONLY.has(fixture.name))
      .map((fixture) => [fixture.name, fixture] as const),
  )("rejects the invalid case: %s", (_name, fixture) => {
    expect(blockSchema.safeParse(asData(fixture.block)).success).toBe(false)
  })

  it("only leaves out cases that cannot reach the API response", () => {
    const names = new Set(fixtures.invalid.map((fixture) => fixture.name))
    for (const name of SERVER_ONLY) expect(names).toContain(name)
  })
})

describe("block schemas", () => {
  it("only accepts images served by the Academy media route", () => {
    const image = (url: string) =>
      blockSchema.safeParse({
        type: "image",
        data: { url, alt: "x", caption: null, width: 10, height: 10 },
      }).success

    expect(image(`/api/v1/academy/media/${"a".repeat(64)}.webp`)).toBe(true)
    expect(image("https://evil.example/x.png")).toBe(false)
    expect(image("//evil.example/x.png")).toBe(false)
    expect(image(`/storage/academy/media/aa/${"a".repeat(64)}.png`)).toBe(false)
    expect(image(`/api/v1/academy/media/../../me`)).toBe(false)
    expect(image(`javascript:alert(1)`)).toBe(false)
  })

  it("only accepts YouTube video ids, never URLs", () => {
    const video = (video_id: string, provider = "youtube") =>
      blockSchema.safeParse({
        type: "video",
        data: { provider, video_id, title: "Vídeo", start_seconds: null },
      }).success

    expect(video("AbCdEfGhIjK")).toBe(true)
    expect(video("https://youtu.be/AbCdEfGhIjK")).toBe(false)
    expect(video("AbCdEfGhIj")).toBe(false)
    expect(video("AbCdEfGhIjK", "vimeo")).toBe(false)
  })

  it("never accepts quiz answers in quiz_ref", () => {
    const result = blockSchema.safeParse({
      type: "quiz_ref",
      data: {
        quiz: "meu-quiz",
        title: "Quiz",
        question_count: 1,
        max_attempts: 3,
        pass_percent: 70,
        questions: [{ prompt: "?", options: [{ text: "A", correct: true }] }],
      },
    })
    expect(result.success).toBe(false)
  })

  it("drops invalid blocks and keeps the rest of the lesson", () => {
    const { blocks, dropped } = parseBlocks([
      { type: "markdown", data: { text: "## Ok" } },
      { type: "html", data: { html: "<script>alert(1)</script>" } },
      { type: "markdown", data: { text: "<img src=x onerror=alert(1)>" } },
      { type: "callout", data: { variant: "danger", title: null, text: "x" } },
      null,
      "texto solto",
    ])

    expect(blocks).toEqual([{ type: "markdown", data: { text: "## Ok" } }])
    expect(dropped).toBe(5)
  })
})

describe("response schemas", () => {
  it("validates a course list page", () => {
    const list = {
      data: [
        {
          slug: "fundamentos-cs2",
          title: "Fundamentos",
          summary: "Resumo.",
          level: "beginner",
          cover_image_url: null,
          module_count: 1,
          lesson_count: 2,
          estimated_minutes: null,
        },
      ],
      links: { first: "/api/v1/academy/courses?page=1" },
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    }
    expect(courseListSchema.safeParse(list).success).toBe(true)
    expect(
      courseListSchema.safeParse({ ...list, data: [{ ...list.data[0], level: "pro" }] }).success,
    ).toBe(false)
  })

  it("rejects slugs that could change the API path", () => {
    const base = {
      slug: "ok",
      title: "T",
      summary: "S",
      level: "beginner",
      cover_image_url: null,
      modules: [],
    }
    expect(courseSchema.safeParse(base).success).toBe(true)
    expect(courseSchema.safeParse({ ...base, slug: "../me" }).success).toBe(false)
    expect(courseSchema.safeParse({ ...base, slug: "Fundamentos" }).success).toBe(false)
  })

  it("keeps lesson blocks unparsed until parseBlocks", () => {
    const lesson = {
      slug: "a",
      title: "A",
      summary: null,
      estimated_minutes: null,
      course: { slug: "c", title: "C" },
      module: { slug: "m", title: "M" },
      content: { version: 1, blocks: [{ type: "anything" }] },
      content_hash: "c".repeat(64),
      previous: null,
      next: null,
    }
    expect(lessonSchema.safeParse(lesson).success).toBe(true)
    expect(lessonSchema.safeParse({ ...lesson, content: { version: 2, blocks: [] } }).success).toBe(
      false,
    )
  })
})
