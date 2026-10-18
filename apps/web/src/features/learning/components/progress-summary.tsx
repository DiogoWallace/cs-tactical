import { cn } from "@/lib/utils"

import { formatPercent } from "../lib/schemas"

/**
 * Barra de progresso do curso: `<progress>` nativo (papel de progressbar e valor já expostos ao
 * leitor de tela, sem `style` inline, que a CSP Report-Only não aceita). O texto visível repete os
 * números.
 */
export function ProgressSummary({
  percent,
  completed,
  total,
  className,
}: {
  percent: number
  completed: number
  total: number
  className?: string
}) {
  const label = `${completed} de ${total} ${total === 1 ? "aula concluída" : "aulas concluídas"}`

  return (
    <div className={cn("flex flex-col gap-2", className)}>
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-mono font-medium">{formatPercent(percent)}</span>
      </div>
      <progress
        value={percent}
        max={100}
        aria-label="Progresso do curso"
        aria-valuetext={`${formatPercent(percent)}, ${label}`}
        className="h-2 w-full appearance-none overflow-hidden rounded-full bg-muted [&::-moz-progress-bar]:rounded-full [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-primary"
      />
    </div>
  )
}
