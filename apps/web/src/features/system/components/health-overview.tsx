import { ServerOffIcon } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from "@/components/ui/empty"
import { Skeleton } from "@/components/ui/skeleton"
import { getHealth } from "@/features/system/api/get-health"
import { ApiUnavailableError } from "@/lib/api/errors"
import type { HealthResponse } from "@/lib/api/types"

import { StatusBadge } from "./status-badge"

const dependencyNames: Record<string, string> = {
  database: "PostgreSQL",
  redis: "Redis",
}

/** Server Component: consulta a API pela rede interna, no momento da requisição. */
export async function HealthOverview() {
  let health: HealthResponse
  try {
    health = await getHealth()
  } catch (error) {
    if (error instanceof ApiUnavailableError) return <ApiUnavailable />
    throw error
  }

  const checks = Object.entries(health.checks ?? {})

  return (
    <div className="grid gap-4 sm:grid-cols-3">
      <Card>
        <CardHeader>
          <CardDescription>API Laravel</CardDescription>
          <CardTitle className="flex items-center justify-between gap-2 text-lg">
            /api/v1/health
            <StatusBadge status={health.status} />
          </CardTitle>
        </CardHeader>
        <CardContent className="text-sm text-muted-foreground">
          Verificado às {new Date(health.timestamp).toLocaleTimeString("pt-BR")}
        </CardContent>
      </Card>
      {checks.map(([name, check]) => (
        <Card key={name}>
          <CardHeader>
            <CardDescription>Dependência</CardDescription>
            <CardTitle className="flex items-center justify-between gap-2 text-lg">
              {dependencyNames[name] ?? name}
              <StatusBadge status={check.status} />
            </CardTitle>
          </CardHeader>
          <CardContent className="font-mono text-sm text-muted-foreground">
            {check.latency_ms.toFixed(1)} ms
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

export function HealthOverviewSkeleton() {
  return (
    <div className="grid gap-4 sm:grid-cols-3" aria-busy="true" aria-label="Carregando status">
      {[0, 1, 2].map((i) => (
        <Skeleton key={i} className="h-32 rounded-xl" />
      ))}
    </div>
  )
}

function ApiUnavailable() {
  return (
    <Empty className="border border-dashed">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <ServerOffIcon />
        </EmptyMedia>
        <EmptyTitle>API indisponível</EmptyTitle>
        <EmptyDescription>
          O frontend não conseguiu falar com a API. Verifique os containers com{" "}
          <code className="font-mono">make ps</code>.
        </EmptyDescription>
      </EmptyHeader>
    </Empty>
  )
}
