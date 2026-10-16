"use client"

import { useEffect, useRef, useState } from "react"
import Link from "next/link"
import { useParams, useSearchParams } from "next/navigation"
import { CircleCheckIcon, LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { ApiError } from "@/lib/api/errors"
import { HOME_PATH } from "@/lib/routes"

import { verifyEmail } from "../api/auth"
import { toFormErrors } from "../lib/form-errors"
import { FormAlert } from "./form-field"
import { ResendVerificationButton } from "./resend-verification-button"

type Status = "verifying" | "verified" | "invalid" | "error"

/**
 * Página aberta pelo link do e-mail: /verificar-email/{id}/{hash}?expires=...&signature=...
 *
 * Chama a API com o mesmo caminho e a mesma query (a assinatura cobre os dois). Sem sessão,
 * o apiClient leva ao login e volta para cá depois.
 */
export function VerifyEmailStatus() {
  const { id, hash } = useParams<{ id: string; hash: string }>()
  const query = useSearchParams().toString()
  const [status, setStatus] = useState<Status>("verifying")
  const [error, setError] = useState<string | null>(null)
  const started = useRef(false)

  useEffect(() => {
    // Uma chamada só, mesmo com o efeito executado duas vezes em desenvolvimento.
    if (started.current) return
    started.current = true

    const apiPath =
      `/api/v1/auth/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${query}` as const
    verifyEmail(apiPath)
      .then(() => setStatus("verified"))
      .catch((caught: unknown) => {
        if (caught instanceof ApiError && caught.status === 401) return // redirecionando ao login
        if (caught instanceof ApiError && caught.status === 403) {
          setStatus("invalid")
          return
        }
        setError(toFormErrors(caught).form)
        setStatus("error")
      })
  }, [id, hash, query])

  if (status === "verifying") {
    return (
      <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircleIcon className="size-4 animate-spin" />
        Confirmando seu e-mail…
      </p>
    )
  }

  if (status === "verified") {
    return (
      <div className="flex flex-col gap-4">
        <p role="status" className="flex gap-2 text-sm">
          <CircleCheckIcon className="mt-0.5 size-4 shrink-0 text-success" />
          E-mail confirmado. Obrigado!
        </p>
        <Button asChild>
          <Link href={HOME_PATH}>Ir para o painel</Link>
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <FormAlert
        message={
          status === "invalid"
            ? "Este link é inválido, expirou ou pertence a outra conta. Peça um novo abaixo."
            : error
        }
      />
      <ResendVerificationButton />
    </div>
  )
}
