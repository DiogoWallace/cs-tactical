"use client"

import { useEffect, useRef, useState } from "react"
import Link from "next/link"
import { CircleCheckIcon, LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { ApiError } from "@/lib/api/errors"

import { completeSteamLink } from "../api/account"
import { readSteamReturn, steamErrorMessage } from "../lib/steam-link"
import { FormAlert } from "./form-field"

export const ACCOUNT_PATH = "/painel/conta"

type Status = { kind: "linking" } | { kind: "linked" } | { kind: "error"; message: string }

/**
 * Volta do login da Steam: /painel/conta/steam?state=…&openid.*=… (etapa M2).
 *
 * Lê a query, apaga-a do histórico (`history.replaceState`, como na redefinição de senha) e envia
 * à API, que confere tudo. Nenhum parâmetro recebido é exibido: só mensagens pelo `code`.
 */
export function SteamLinkReturn() {
  const [status, setStatus] = useState<Status>({ kind: "linking" })
  const started = useRef(false)
  const title = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    // Uma chamada só, mesmo com o efeito executado duas vezes em desenvolvimento: o `state` vale
    // uma vez.
    if (started.current) return
    started.current = true
    title.current?.focus()

    const { pathname, search } = window.location
    const { state, openid } = readSteamReturn(search)
    window.history.replaceState(window.history.state, "", pathname)

    completeSteamLink(state, openid)
      .then(() => setStatus({ kind: "linked" }))
      .catch((caught: unknown) => {
        if (caught instanceof ApiError && caught.status === 401) return // redirecionando ao login
        setStatus({ kind: "error", message: steamErrorMessage(caught) })
      })
  }, [])

  return (
    <div className="flex flex-col gap-4">
      <h1 ref={title} tabIndex={-1} className="text-3xl font-semibold tracking-tight outline-none">
        Conta Steam
      </h1>

      {status.kind === "linking" && (
        <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground">
          <LoaderCircleIcon className="size-4 animate-spin" />
          Confirmando o vínculo com a Steam…
        </p>
      )}

      {status.kind === "linked" && (
        <p role="status" className="flex gap-2 text-sm">
          <CircleCheckIcon className="mt-0.5 size-4 shrink-0 text-success" />
          Conta Steam vinculada.
        </p>
      )}

      {status.kind === "error" && <FormAlert message={status.message} />}

      {status.kind !== "linking" && (
        <Button
          asChild
          variant={status.kind === "linked" ? "default" : "outline"}
          className="self-start"
        >
          <Link href={ACCOUNT_PATH}>Voltar para a conta</Link>
        </Button>
      )}
    </div>
  )
}
