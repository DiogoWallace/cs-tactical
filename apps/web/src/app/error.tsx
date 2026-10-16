"use client"

import { TriangleAlertIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty"

/** Fronteira de erro padrão: nenhuma mensagem técnica é mostrada ao usuário. */
export default function Error({
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  return (
    <Empty className="flex-1">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <TriangleAlertIcon />
        </EmptyMedia>
        <EmptyTitle>Algo deu errado</EmptyTitle>
        <EmptyDescription>Não foi possível carregar esta página. Tente novamente.</EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <Button onClick={reset}>Tentar novamente</Button>
      </EmptyContent>
    </Empty>
  )
}
