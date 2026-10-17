import { InfoIcon, LightbulbIcon, TriangleAlertIcon, type LucideIcon } from "lucide-react"

import { cn } from "@/lib/utils"

import type { CalloutBlock as CalloutBlockData } from "../../lib/schemas"
import { SafeMarkdown } from "../safe-markdown"

const VARIANTS: Record<
  CalloutBlockData["data"]["variant"],
  { label: string; icon: LucideIcon; className: string; iconClassName: string }
> = {
  info: {
    label: "Informação",
    icon: InfoIcon,
    className: "border-primary/40 bg-primary/5",
    iconClassName: "text-primary",
  },
  tip: {
    label: "Dica",
    icon: LightbulbIcon,
    className: "border-success/40 bg-success/5",
    iconClassName: "text-success",
  },
  warning: {
    label: "Atenção",
    icon: TriangleAlertIcon,
    className: "border-warning/50 bg-warning/5",
    iconClassName: "text-warning",
  },
}

/**
 * Destaque (`info`, `tip`, `warning`). O tipo vai no texto (rótulo visível), não só na cor; o
 * ícone é decorativo. Texto em Markdown em linha (negrito, itálico, código e links).
 */
export function CalloutBlock({ data }: { data: CalloutBlockData["data"] }) {
  const variant = VARIANTS[data.variant]
  const Icon = variant.icon

  return (
    // `note`, não `aside`: um destaque dentro do texto não é um landmark da página.
    <div
      role="note"
      aria-label={data.title ?? variant.label}
      data-variant={data.variant}
      className={cn("flex gap-3 rounded-lg border p-4", variant.className)}
    >
      <Icon aria-hidden className={cn("mt-0.5 size-5 shrink-0", variant.iconClassName)} />
      <div className="min-w-0 flex-1">
        <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
          {variant.label}
        </p>
        {data.title && <p className="mt-1 font-semibold">{data.title}</p>}
        <SafeMarkdown text={data.text} inline className="mt-1 text-sm leading-6" />
      </div>
    </div>
  )
}
