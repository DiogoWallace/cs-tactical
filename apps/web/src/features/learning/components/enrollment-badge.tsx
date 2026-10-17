import { CheckCircle2Icon } from "lucide-react"

import { Badge } from "@/components/ui/badge"

import { formatPercent, type EnrollmentSummary } from "../lib/schemas"

/** Marca do jogador num cartão do catálogo: matriculado (com o percentual) ou curso concluído. */
export function EnrollmentBadge({ enrollment }: { enrollment: EnrollmentSummary }) {
  if (enrollment.completed_at !== null) {
    return (
      <Badge variant="secondary" className="text-success">
        <CheckCircle2Icon aria-hidden />
        Curso concluído
      </Badge>
    )
  }

  const { percent } = enrollment.progress
  return (
    <Badge variant="secondary">
      Matriculado{percent !== null && ` · ${formatPercent(percent)}`}
    </Badge>
  )
}
