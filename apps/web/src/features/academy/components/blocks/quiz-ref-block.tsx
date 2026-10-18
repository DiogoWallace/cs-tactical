import { ListChecksIcon } from "lucide-react"

import type { QuizRefBlock as QuizRefBlockData } from "../../lib/schemas"

function plural(count: number, singular: string, pluralForm: string): string {
  return `${count} ${count === 1 ? singular : pluralForm}`
}

/**
 * Quiz da aula, com os números públicos da API (ADR 0018). Responder, corrigir e ver o resultado
 * (Learning, etapa 2.5) entram como `children`, montados pela página; a Academy não conhece o
 * Learning e este bloco nunca tem perguntas nem gabarito.
 */
export function QuizRefBlock({
  data,
  children,
}: {
  data: QuizRefBlockData["data"]
  /** O quiz respondível (etapa 2.5), quando a página o fornece. */
  children?: React.ReactNode
}) {
  return (
    <section
      aria-labelledby={`quiz-${data.quiz}`}
      className="rounded-lg border border-dashed bg-card p-5"
    >
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
          <ListChecksIcon aria-hidden className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
            Quiz da aula
          </p>
          <h2 id={`quiz-${data.quiz}`} className="mt-1 text-lg font-semibold">
            {data.title}
          </h2>
          <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground">
            <li>{plural(data.question_count, "pergunta", "perguntas")}</li>
            <li>Aprovação com {data.pass_percent}%</li>
            <li>{plural(data.max_attempts, "tentativa", "tentativas")}</li>
          </ul>
          {children ?? (
            <p className="mt-3 text-sm text-muted-foreground">
              Abra a aula com a sua conta para responder a este quiz.
            </p>
          )}
        </div>
      </div>
    </section>
  )
}
