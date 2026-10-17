import Link from "next/link"
import { CrosshairIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
} from "@/components/ui/empty"
import { ACADEMY_PATH } from "@/features/academy/lib/paths"

/**
 * Curso ou aula inexistente ou não público. A mesma mensagem para os dois casos: a página não
 * revela que um rascunho existe (a API responde 404 igual).
 */
export default function AcademyNotFound() {
  return (
    <Empty className="flex-1 py-16">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <CrosshairIcon aria-hidden />
        </EmptyMedia>
        <h1 className="text-lg font-medium tracking-tight">Conteúdo não encontrado</h1>
        <EmptyDescription>
          Este curso ou aula não existe ou não está disponível no momento.
        </EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <Button asChild>
          <Link href={ACADEMY_PATH}>Ver os cursos da Academy</Link>
        </Button>
      </EmptyContent>
    </Empty>
  )
}
