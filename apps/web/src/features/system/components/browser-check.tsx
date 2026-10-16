"use client"

import { useState } from "react"
import { RefreshCwIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { apiClient, ensureCsrfCookie, readXsrfToken } from "@/lib/api/client"
import type { HealthResponse } from "@/lib/api/types"

import { StatusBadge } from "./status-badge"

type Result =
  | { state: "idle" }
  | { state: "loading" }
  | { state: "done"; health: HealthResponse["status"]; csrfCookie: boolean }
  | { state: "error" }

/**
 * Client Component: faz o mesmo caminho que o login fará na Fase 1.
 * O navegador chama /sanctum/csrf-cookie e /api/v1/health na própria origem do frontend,
 * e o Next encaminha para o Laravel.
 */
export function BrowserCheck() {
  const [result, setResult] = useState<Result>({ state: "idle" })

  async function run() {
    setResult({ state: "loading" })
    try {
      await ensureCsrfCookie(true)
      const health = await apiClient.get<HealthResponse>("/api/v1/health").catch(() => null)
      setResult({
        state: "done",
        health: health?.status ?? "degraded",
        csrfCookie: readXsrfToken() !== null,
      })
    } catch {
      setResult({ state: "error" })
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardDescription>Navegador → Next.js → Laravel</CardDescription>
        <CardTitle className="text-lg">Proxy de mesma origem</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">Health pelo proxy</dt>
          <dd>{result.state === "done" ? <StatusBadge status={result.health} /> : "—"}</dd>
          <dt className="text-muted-foreground">Cookie XSRF-TOKEN</dt>
          <dd>
            {result.state === "done" ? (
              <StatusBadge
                status={result.csrfCookie ? "ok" : "fail"}
                label={result.csrfCookie ? "Recebido" : "Ausente"}
              />
            ) : (
              "—"
            )}
          </dd>
        </dl>
        <Button onClick={run} disabled={result.state === "loading"} variant="outline">
          <RefreshCwIcon className={result.state === "loading" ? "animate-spin" : undefined} />
          Testar pelo navegador
        </Button>
      </CardContent>
      {result.state === "error" && (
        <CardContent className="text-sm text-destructive">
          Não foi possível alcançar a API pelo proxy.
        </CardContent>
      )}
    </Card>
  )
}
