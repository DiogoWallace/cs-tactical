"use client"

import { useState } from "react"
import { PlayIcon } from "lucide-react"

import { YOUTUBE_FRAME_ORIGIN } from "@/lib/security-headers"

import type { VideoBlock as VideoBlockData } from "../../lib/schemas"

const VIDEO_ID = /^[A-Za-z0-9_-]{11}$/

/**
 * URL do player (o único provedor da Fase 2, o mesmo liberado na CSP das aulas) a partir do ID validado (nunca uma URL ou HTML vindos do conteúdo). Devolve
 * null para um ID fora do formato.
 */
export function youtubeEmbedUrl(videoId: string, startSeconds: number | null): string | null {
  if (!VIDEO_ID.test(videoId)) return null
  const params = new URLSearchParams({ autoplay: "1", rel: "0" })
  if (startSeconds) params.set("start", String(startSeconds))
  return `${YOUTUBE_FRAME_ORIGIN}/embed/${videoId}?${params}`
}

/**
 * Vídeo do YouTube carregado só quando a pessoa pede: até o clique, nenhuma requisição sai para
 * o YouTube (nada de cookies, scripts ou rastreamento de terceiros só por abrir a aula).
 *
 * O `iframe` segue a ADR 0019: domínio sem cookies, `title` obrigatório, `loading="lazy"`,
 * `referrerpolicy="strict-origin-when-cross-origin"` e `sandbox` com o mínimo para tocar.
 */
export function VideoBlock({ data }: { data: VideoBlockData["data"] }) {
  const [playing, setPlaying] = useState(false)
  const src = youtubeEmbedUrl(data.video_id, data.start_seconds)
  if (src === null) return null

  return (
    <figure className="flex flex-col gap-2">
      <div className="relative aspect-video w-full overflow-hidden rounded-lg border bg-muted">
        {playing ? (
          <iframe
            src={src}
            title={data.title}
            loading="lazy"
            referrerPolicy="strict-origin-when-cross-origin"
            sandbox="allow-scripts allow-same-origin allow-presentation"
            allow="encrypted-media; picture-in-picture; fullscreen"
            allowFullScreen
            className="absolute inset-0 size-full"
          />
        ) : (
          <button
            type="button"
            aria-label={`Assistir ao vídeo: ${data.title}`}
            aria-describedby={`video-aviso-${data.video_id}`}
            onClick={() => setPlaying(true)}
            className="group absolute inset-0 flex flex-col items-center justify-center gap-3 bg-[radial-gradient(circle_at_center,var(--accent)_0%,transparent_70%)] p-4 text-center outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-inset"
          >
            <span className="flex size-16 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform group-hover:scale-105 motion-reduce:transition-none">
              <PlayIcon aria-hidden className="size-7 translate-x-0.5" />
            </span>
            <span className="font-medium">{data.title}</span>
            <span id={`video-aviso-${data.video_id}`} className="text-xs text-muted-foreground">
              O vídeo é carregado do YouTube só depois do clique.
            </span>
          </button>
        )}
      </div>
    </figure>
  )
}
