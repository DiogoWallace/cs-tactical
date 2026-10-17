import { z } from "zod"

import { isSafeBlockMarkdown, isSafeInlineMarkdown } from "./markdown-rules"

/**
 * Respostas da API da Academy (docs/api/academy-v1.md), validadas no servidor do Next antes de
 * renderizar. Blocos: espelho dos schemas v1 em PHP (`App\Modules\Academy\Content\Blocks`), no
 * formato **resolvido** que a API entrega (imagem com URL e dimensões, quiz com resumo). Os casos
 * de `content/academy/_fixtures/blocks-v1.json` são testados contra os dois lados.
 *
 * A validação daqui não autoriza nada (quem decide o que é público é a API): ela só garante que
 * o que chega ao React tem a forma esperada, sem HTML nem URLs fora das permitidas.
 */

export const COURSE_LEVELS = ["beginner", "intermediate", "advanced"] as const
export const CALLOUT_VARIANTS = ["info", "tip", "warning"] as const

const slug = z
  .string()
  .max(80)
  .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/)

/** Texto com conteúdo (o PHP recusa só espaços). */
const text = (max: number) =>
  z
    .string()
    .max(max)
    .refine((value) => value.trim().length > 0)

/** Só a rota de mídia da própria API (ADR 0019): nenhuma URL externa nem caminho do Storage. */
export const MEDIA_URL = /^\/api\/v1\/academy\/media\/[0-9a-f]{64}\.(?:png|jpg|webp|avif)$/

const mediaUrl = z.string().regex(MEDIA_URL)

export const markdownBlockSchema = z.strictObject({
  type: z.literal("markdown"),
  data: z.strictObject({ text: text(20_000).refine(isSafeBlockMarkdown) }),
})

export const calloutBlockSchema = z.strictObject({
  type: z.literal("callout"),
  data: z.strictObject({
    variant: z.enum(CALLOUT_VARIANTS),
    title: text(120).nullable(),
    text: text(2_000).refine(isSafeInlineMarkdown),
  }),
})

export const videoBlockSchema = z.strictObject({
  type: z.literal("video"),
  data: z.strictObject({
    provider: z.literal("youtube"),
    video_id: z.string().regex(/^[A-Za-z0-9_-]{11}$/),
    title: text(160),
    start_seconds: z.int().min(0).max(86_400).nullable(),
  }),
})

export const imageBlockSchema = z.strictObject({
  type: z.literal("image"),
  data: z.strictObject({
    url: mediaUrl,
    // Vazio = imagem decorativa (a API não manda `decorative`; o PHP exige alt nos demais casos).
    alt: z.string().max(300),
    caption: text(300).nullable(),
    width: z.int().min(1).max(4096),
    height: z.int().min(1).max(4096),
  }),
})

/** Só o resumo público do quiz: perguntas e gabarito nunca vêm nesta resposta (ADR 0018). */
export const quizRefBlockSchema = z.strictObject({
  type: z.literal("quiz_ref"),
  data: z.strictObject({
    quiz: slug,
    title: text(160),
    question_count: z.int().min(0).max(30),
    max_attempts: z.int().min(1).max(20),
    pass_percent: z.int().min(1).max(100),
  }),
})

export const blockSchema = z.discriminatedUnion("type", [
  markdownBlockSchema,
  calloutBlockSchema,
  videoBlockSchema,
  imageBlockSchema,
  quizRefBlockSchema,
])

const lessonLink = z.object({ slug, title: text(160) })

export const courseSummarySchema = z.object({
  slug,
  title: text(160),
  summary: text(500),
  level: z.enum(COURSE_LEVELS),
  cover_image_url: mediaUrl.nullable(),
  module_count: z.int().min(0),
  lesson_count: z.int().min(0),
  estimated_minutes: z.int().min(0).nullable(),
})

export const courseListSchema = z.object({
  data: z.array(courseSummarySchema),
  meta: z.object({
    current_page: z.int().min(1),
    last_page: z.int().min(1),
    per_page: z.int().min(1),
    total: z.int().min(0),
  }),
})

export const courseSchema = z.object({
  slug,
  title: text(160),
  summary: text(500),
  level: z.enum(COURSE_LEVELS),
  cover_image_url: mediaUrl.nullable(),
  modules: z.array(
    z.object({
      slug,
      title: text(160),
      lessons: z.array(
        lessonLink.extend({
          estimated_minutes: z.int().min(1).max(600).nullable(),
          has_quiz: z.boolean(),
        }),
      ),
    }),
  ),
})

/**
 * Aula. Os blocos chegam como `unknown` e são validados um a um (`parseBlocks`): um bloco
 * inválido é descartado sem derrubar a aula inteira.
 */
export const lessonSchema = z.object({
  slug,
  title: text(160),
  summary: text(500).nullable(),
  estimated_minutes: z.int().min(1).max(600).nullable(),
  course: lessonLink,
  module: lessonLink,
  content: z.object({ version: z.literal(1), blocks: z.array(z.unknown()).max(100) }),
  content_hash: z.string().regex(/^[0-9a-f]{64}$/),
  previous: lessonLink.nullable(),
  next: lessonLink.nullable(),
})

export type CourseLevel = (typeof COURSE_LEVELS)[number]
export type CourseSummary = z.infer<typeof courseSummarySchema>
export type CourseList = z.infer<typeof courseListSchema>
export type Course = z.infer<typeof courseSchema>
export type CourseModule = Course["modules"][number]
export type CourseLessonLink = CourseModule["lessons"][number]
export type Block = z.infer<typeof blockSchema>
export type MarkdownBlock = z.infer<typeof markdownBlockSchema>
export type CalloutBlock = z.infer<typeof calloutBlockSchema>
export type VideoBlock = z.infer<typeof videoBlockSchema>
export type ImageBlock = z.infer<typeof imageBlockSchema>
export type QuizRefBlock = z.infer<typeof quizRefBlockSchema>
export type Lesson = Omit<z.infer<typeof lessonSchema>, "content"> & {
  content: { version: 1; blocks: Block[] }
}

/** Valida cada bloco; os inválidos (tipo desconhecido, HTML, URL estranha) não são renderizados. */
export function parseBlocks(blocks: unknown[]): { blocks: Block[]; dropped: number } {
  const valid: Block[] = []
  for (const block of blocks) {
    const result = blockSchema.safeParse(block)
    if (result.success) valid.push(result.data)
  }
  return { blocks: valid, dropped: blocks.length - valid.length }
}
