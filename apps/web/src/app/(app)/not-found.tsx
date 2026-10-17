import Link from "next/link"
import { CompassIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty"
import { HOME_PATH } from "@/lib/routes"

/** Página inexistente dentro da área logada: mantém o cabeçalho e volta ao painel. */
export default function AppNotFound() {
  return (
    <Empty className="flex-1">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <CompassIcon />
        </EmptyMedia>
        <EmptyTitle>Página não encontrada</EmptyTitle>
        <EmptyDescription>O endereço acessado não existe ou foi movido.</EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <Button asChild variant="outline">
          <Link href={HOME_PATH}>Voltar ao painel</Link>
        </Button>
      </EmptyContent>
    </Empty>
  )
}
