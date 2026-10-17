import type { Block } from "../../lib/schemas"
import { SafeMarkdown } from "../safe-markdown"
import { CalloutBlock } from "./callout-block"
import { ImageBlock } from "./image-block"
import { QuizRefBlock } from "./quiz-ref-block"
import { VideoBlock } from "./video-block"

/**
 * Conteúdo da aula, um renderizador por tipo do schema v1. Os blocos já passaram pelo Zod
 * (`parseBlocks`); não há caminho para HTML ou tipo desconhecido.
 */
export function LessonBlocks({ blocks }: { blocks: Block[] }) {
  return (
    <div className="flex flex-col gap-8">
      {blocks.map((block, index) => (
        <div key={index} data-block={block.type}>
          {renderBlock(block)}
        </div>
      ))}
    </div>
  )
}

function renderBlock(block: Block) {
  switch (block.type) {
    case "markdown":
      return <SafeMarkdown text={block.data.text} />
    case "callout":
      return <CalloutBlock data={block.data} />
    case "video":
      return <VideoBlock data={block.data} />
    case "image":
      return <ImageBlock data={block.data} />
    case "quiz_ref":
      return <QuizRefBlock data={block.data} />
  }
}
