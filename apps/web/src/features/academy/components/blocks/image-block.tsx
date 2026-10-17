"use client"

import { useCallback, useState } from "react"
import { ImageOffIcon } from "lucide-react"

import type { ImageBlock as ImageBlockData } from "../../lib/schemas"

/**
 * Imagem da aula, pela URL que a API devolve (`/api/v1/academy/media/...`, mesma origem): a API
 * decide se a imagem pode ser entregue. Sem otimização do Next (`next/image` buscaria a imagem
 * no servidor, sem a sessão do usuário, e guardaria uma cópia em cache — a API manda `no-store`).
 *
 * - `alt` vazio = decorativa: some da árvore de acessibilidade.
 * - `width`/`height` reservam o espaço (sem salto de layout); a imagem nunca passa da coluna.
 * - Falha ao carregar: aviso no lugar, com o texto alternativo.
 */
export function ImageBlock({ data }: { data: ImageBlockData["data"] }) {
  const [failed, setFailed] = useState(false)
  const decorative = data.alt === ""

  // O HTML vem do servidor: uma falha antes da hidratação não chega ao `onError` do React.
  const checkLoaded = useCallback((image: HTMLImageElement | null) => {
    if (image?.complete && image.naturalWidth === 0) setFailed(true)
  }, [])

  return (
    <figure className="flex flex-col gap-2">
      {failed ? (
        <div
          role={decorative ? undefined : "img"}
          aria-label={decorative ? undefined : `Imagem indisponível: ${data.alt}`}
          aria-hidden={decorative || undefined}
          className="flex items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/40 p-6 text-sm text-muted-foreground"
          style={{ aspectRatio: `${data.width} / ${data.height}` }}
        >
          <ImageOffIcon aria-hidden className="size-5" />
          <span>Não foi possível carregar a imagem.</span>
        </div>
      ) : (
        // eslint-disable-next-line @next/next/no-img-element -- ver comentário do componente
        <img
          ref={checkLoaded}
          src={data.url}
          alt={data.alt}
          width={data.width}
          height={data.height}
          loading="lazy"
          decoding="async"
          onError={() => setFailed(true)}
          className="h-auto w-full max-w-full rounded-lg border bg-muted/40 object-contain"
          style={{ maxWidth: `${data.width}px` }}
        />
      )}
      {data.caption && (
        <figcaption className="text-sm text-muted-foreground">{data.caption}</figcaption>
      )}
    </figure>
  )
}
