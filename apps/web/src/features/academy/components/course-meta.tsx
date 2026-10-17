import {
  ClockIcon,
  LayersIcon,
  PlayCircleIcon,
  SignalHighIcon,
  SignalLowIcon,
  SignalMediumIcon,
  type LucideIcon,
} from "lucide-react"

import { cn } from "@/lib/utils"

import type { CourseLevel } from "../lib/schemas"

export const LEVELS: Record<CourseLevel, { label: string; icon: LucideIcon; className: string }> = {
  beginner: { label: "Iniciante", icon: SignalLowIcon, className: "text-success" },
  intermediate: { label: "Intermediário", icon: SignalMediumIcon, className: "text-warning" },
  advanced: { label: "Avançado", icon: SignalHighIcon, className: "text-destructive" },
}

/** Nível de dificuldade: o nome vai em texto; ícone e cor só reforçam. */
export function LevelBadge({ level, className }: { level: CourseLevel; className?: string }) {
  const { label, icon: Icon, className: tone } = LEVELS[level]
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full border bg-background/80 px-2.5 py-0.5 text-xs font-medium backdrop-blur-sm",
        className,
      )}
    >
      <Icon aria-hidden className={cn("size-3.5", tone)} />
      {label}
    </span>
  )
}

/** "1 h 35 min", "45 min". */
export function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return `${rest} min`
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`
}

function plural(count: number, singular: string, pluralForm: string): string {
  return `${count} ${count === 1 ? singular : pluralForm}`
}

type Props = {
  moduleCount: number
  lessonCount: number
  minutes: number | null
  className?: string
}

/** Números do curso em lista (leitor de tela lê cada um), com ícones decorativos. */
export function CourseStats({ moduleCount, lessonCount, minutes, className }: Props) {
  return (
    <ul className={cn("flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground", className)}>
      <li className="inline-flex items-center gap-1.5">
        <LayersIcon aria-hidden className="size-4" />
        {plural(moduleCount, "módulo", "módulos")}
      </li>
      <li className="inline-flex items-center gap-1.5">
        <PlayCircleIcon aria-hidden className="size-4" />
        {plural(lessonCount, "aula", "aulas")}
      </li>
      {minutes !== null && minutes > 0 && (
        <li className="inline-flex items-center gap-1.5">
          <ClockIcon aria-hidden className="size-4" />
          <span>
            <span className="sr-only">Duração estimada: </span>
            {formatDuration(minutes)}
          </span>
        </li>
      )}
    </ul>
  )
}
