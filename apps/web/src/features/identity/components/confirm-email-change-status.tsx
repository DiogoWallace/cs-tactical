"use client"

import { useEffect, useRef, useState } from "react"
import Link from "next/link"
import { useParams, useSearchParams } from "next/navigation"
import { CircleCheckIcon, LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { ApiError } from "@/lib/api/errors"
import { ACCOUNT_PATH } from "@/lib/routes"

import { confirmEmailChange } from "../api/account"
import { toFormErrors } from "../lib/form-errors"
import { FormAlert } from "./form-field"

type State =
  | { status: "confirming" }
  | { status: "confirmed"; email: string }
  | { status: "failed"; message: string | null }

/**
 * Página aberta pelo link enviado ao novo endereço: /confirmar-email/{id}/{hash}?expires&signature
 * Chama a API com o mesmo caminho e a mesma query (ADR 0011). Sem sessão, o apiClient leva ao
 * login e volta para cá.
 */
export function ConfirmEmailChangeStatus() {
  const { id, hash } = useParams<{ id: string; hash: string }>()
  const query = useSearchParams().toString()
  const [state, setState] = useState<State>({ status: "confirming" })
  const started = useRef(false)

  useEffect(() => {
    // Uma chamada só, mesmo com o efeito executado duas vezes em desenvolvimento.
    if (started.current) return
    started.current = true

    const apiPath =
      `/api/v1/me/email/confirm/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${query}` as const
    confirmEmailChange(apiPath)
      .then((user) => setState({ status: "confirmed", email: user.email }))
      .catch((caught: unknown) => {
        if (caught instanceof ApiError && caught.status === 401) return // redirecionando ao login
        if (caught instanceof ApiError && caught.status === 403) {
          setState({
            status: "failed",
            message:
              "Este link é inválido, expirou, foi substituído por um pedido mais novo ou pertence a outra conta.",
          })
          return
        }
        const { fields, form } = toFormErrors(caught)
        setState({ status: "failed", message: fields.email ?? form })
      })
  }, [id, hash, query])

  if (state.status === "confirming") {
    return (
      <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircleIcon className="size-4 animate-spin" />
        Confirmando o novo e-mail…
      </p>
    )
  }

  if (state.status === "confirmed") {
    return (
      <div className="flex flex-col gap-4">
        <p role="status" className="flex gap-2 text-sm">
          <CircleCheckIcon className="mt-0.5 size-4 shrink-0 text-success" />
          <span>
            Pronto. O e-mail da sua conta agora é{" "}
            <span className="font-medium break-all">{state.email}</span>.
          </span>
        </p>
        <Button asChild>
          <Link href={ACCOUNT_PATH}>Voltar para a conta</Link>
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <FormAlert message={state.message} />
      <Button asChild variant="outline">
        <Link href={ACCOUNT_PATH}>Pedir a troca de novo</Link>
      </Button>
    </div>
  )
}
