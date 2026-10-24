import { cn } from "@/lib/utils"

function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      // Sem pulsar para quem pede movimento reduzido (etapa 2.7c, X6).
      className={cn("animate-pulse rounded-md bg-accent motion-reduce:animate-none", className)}
      {...props}
    />
  )
}

export { Skeleton }
