import { useId } from "react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"

type FormFieldProps = Omit<React.ComponentProps<typeof Input>, "id"> & {
  label: string
  hint?: string
  error?: string
}

/** Campo com rótulo, dica e erro ligados por aria-describedby (leitores de tela anunciam o erro). */
export function FormField({ label, hint, error, ...inputProps }: FormFieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint && hintId, error && errorId].filter(Boolean).join(" ") || undefined

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        {...inputProps}
      />
      {hint && !error && (
        <p id={hintId} className="text-xs text-muted-foreground">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}

/** Mensagem geral do formulário (anunciada por leitores de tela). */
export function FormAlert({ message }: { message: string | null }) {
  if (!message) return null
  return (
    <p
      role="alert"
      className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
    >
      {message}
    </p>
  )
}
