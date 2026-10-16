import "server-only"

import { ApiError } from "@/lib/api/errors"
import { serverFetch } from "@/lib/api/server"
import type { HealthResponse } from "@/lib/api/types"

/** Lê o health check da API. Um 503 ainda traz o corpo com o estado de cada dependência. */
export async function getHealth(): Promise<HealthResponse> {
  try {
    return await serverFetch<HealthResponse>("/api/v1/health")
  } catch (error) {
    if (error instanceof ApiError && error.status === 503 && isHealthResponse(error.body)) {
      return error.body
    }
    throw error
  }
}

function isHealthResponse(value: unknown): value is HealthResponse {
  return typeof value === "object" && value !== null && "status" in value
}
