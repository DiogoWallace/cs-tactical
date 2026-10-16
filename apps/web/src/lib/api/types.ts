/** Tipos das respostas da API. Serão gerados a partir do OpenAPI na Fase 1. */

export type DependencyStatus = "ok" | "fail"

export type HealthResponse = {
  status: "ok" | "degraded"
  timestamp: string
  checks?: Record<string, { status: DependencyStatus; latency_ms: number }>
}
