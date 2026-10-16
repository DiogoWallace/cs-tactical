import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

type Status = "ok" | "fail" | "degraded"

const labels: Record<Status, string> = { ok: "Operacional", fail: "Falha", degraded: "Degradado" }

export function StatusBadge({ status, label }: { status: Status; label?: string }) {
  return (
    <Badge
      variant="outline"
      className={cn(
        "gap-1.5",
        status === "ok" && "border-success/40 text-success",
        status === "degraded" && "border-warning/40 text-warning",
        status === "fail" && "border-destructive/40 text-destructive",
      )}
    >
      <span
        aria-hidden
        className={cn(
          "size-1.5 rounded-full",
          status === "ok" && "bg-success",
          status === "degraded" && "bg-warning",
          status === "fail" && "bg-destructive",
        )}
      />
      {label ?? labels[status]}
    </Badge>
  )
}
