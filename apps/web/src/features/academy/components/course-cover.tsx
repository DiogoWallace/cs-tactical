import { CrosshairIcon } from "lucide-react"

import { cn } from "@/lib/utils"

/**
 * Capa do curso (16:9) pela URL da API, ou um padrão de mira quando não há capa. A capa é
 * decorativa: o título do curso está sempre ao lado, em texto.
 */
export function CourseCover({ url, className }: { url: string | null; className?: string }) {
  return (
    <div
      className={cn(
        "relative aspect-video overflow-hidden bg-muted",
        "bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] bg-[size:24px_24px]",
        className,
      )}
    >
      {url ? (
        // eslint-disable-next-line @next/next/no-img-element -- mídia da API com no-store; ver ImageBlock
        <img
          src={url}
          alt=""
          loading="lazy"
          decoding="async"
          className="absolute inset-0 size-full object-cover"
        />
      ) : (
        <div className="absolute inset-0 flex items-center justify-center text-primary/70">
          <CrosshairIcon aria-hidden className="size-12" strokeWidth={1.25} />
        </div>
      )}
    </div>
  )
}
