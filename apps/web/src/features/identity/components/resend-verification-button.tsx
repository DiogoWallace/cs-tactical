"use client"

import { useState } from "react"
import { LoaderCircleIcon, MailIcon } from "lucide-react"

import { Button } from "@/components/ui/button"

import { resendVerification } from "../api/auth"
import { toFormErrors } from "../lib/form-errors"

/** Reenvia o link de confirmação de e-mail. Limite da API: 6 por minuto. */
export function ResendVerificationButton({ email }: { email?: string }) {
  const [state, setState] = useState<"idle" | "pending" | "sent">("idle")
  const [error, setError] = useState<string | null>(null)

  async function onClick() {
    setState("pending")
    setError(null)
    try {
      await resendVerification()
      setState("sent")
    } catch (caught) {
      setError(toFormErrors(caught).form)
      setState("idle")
    }
  }

  if (state === "sent") {
    return (
      <p role="status" className="text-sm">
        Enviamos um novo link{email ? ` para ${email}` : ""}. Ele vale por 60 minutos.
      </p>
    )
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <Button variant="outline" size="sm" onClick={onClick} disabled={state === "pending"}>
        {state === "pending" ? <LoaderCircleIcon className="animate-spin" /> : <MailIcon />}
        Reenviar e-mail de confirmação
      </Button>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
