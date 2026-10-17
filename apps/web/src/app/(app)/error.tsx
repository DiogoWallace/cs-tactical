"use client"

import { RotateCcwIcon, ServerCrashIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty"

/**
 * Erro numa página da área logada (ex.: API fora do ar). O cabeçalho continua visível.
 * Nenhum detalhe técnico é mostrado; em produção o Next nem envia a mensagem original.
 */
export default function AppError({
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  return (
    <Empty className="flex-1">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <ServerCrashIcon />
        </EmptyMedia>
        <EmptyTitle>Não foi possível carregar esta página</EmptyTitle>
        <EmptyDescription>
          O serviço pode estar indisponível no momento. Verifique sua conexão e tente novamente.
        </EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <Button onClick={reset}>
          <RotateCcwIcon />
          Tentar novamente
        </Button>
      </EmptyContent>
    </Empty>
  )
}
