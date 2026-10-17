import { Skeleton } from "@/components/ui/skeleton"

/** Carregamento ao navegar entre páginas da área logada (o cabeçalho continua visível). */
export default function AppLoading() {
  return (
    <div className="flex flex-col gap-8" role="status" aria-label="Carregando">
      <Skeleton className="h-10 w-64" />
      <div className="grid gap-4 md:grid-cols-3">
        <Skeleton className="h-40" />
        <Skeleton className="h-40" />
        <Skeleton className="h-40" />
      </div>
    </div>
  )
}
