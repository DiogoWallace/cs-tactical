"use client"

import { useId, useState } from "react"
import { LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"

import { startSteamLink, unlinkSteam } from "../api/account"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { navigateToProvider } from "../lib/navigation"
import { steamErrorMessage } from "../lib/steam-link"
import type { CurrentUser } from "../types"
import { FormAlert, FormField } from "./form-field"

const linkedAtFormat = new Intl.DateTimeFormat("pt-BR", { dateStyle: "long" })

/**
 * Seção "Conta Steam" da conta (etapa M2). Vincular vai ao login da Steam e volta para
 * /painel/conta/steam; desvincular exige a senha atual (decisão S2). O SteamID64 completo só
 * aparece aqui, para o próprio usuário (decisão S3).
 */
export function SteamLinkCard({ user }: { user: CurrentUser }) {
  const [steam, setSteam] = useState(user.steam)
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [linkError, setLinkError] = useState<string | null>(null)
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [unlinked, setUnlinked] = useState(false)
  const titleId = useId()

  async function onLink() {
    setBusy(true)
    setLinkError(null)
    setUnlinked(false)
    try {
      // Continua "ocupado" até a página trocar.
      navigateToProvider(await startSteamLink())
    } catch (error) {
      setLinkError(steamErrorMessage(error))
      setBusy(false)
    }
  }

  async function onUnlink(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    setBusy(true)
    setErrors(NO_ERRORS)
    try {
      await unlinkSteam(String(form.get("current_password") ?? ""))
      setSteam(null)
      setConfirming(false)
      setUnlinked(true)
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setBusy(false)
    }
  }

  function onCancel() {
    setConfirming(false)
    setErrors(NO_ERRORS)
  }

  if (!steam) {
    return (
      <div className="flex flex-col gap-4">
        {unlinked && (
          <p role="status" className="text-sm">
            Conta Steam desvinculada.
          </p>
        )}
        <p className="text-sm text-muted-foreground">
          Nenhuma conta Steam vinculada. O vínculo permite que a análise de partidas reconheça qual
          jogador é você.
        </p>
        <FormAlert message={linkError} />
        <Button onClick={onLink} disabled={busy} className="self-start">
          {busy && <LoaderCircleIcon className="animate-spin" />}
          Vincular conta Steam
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <dl className="grid gap-1 text-sm">
        <dt className="text-muted-foreground">SteamID64</dt>
        <dd className="font-mono font-medium break-all">{steam.steam_id}</dd>
        <dt className="mt-2 text-muted-foreground">Vinculada em</dt>
        <dd>{linkedAtFormat.format(new Date(steam.linked_at))}</dd>
      </dl>

      {confirming ? (
        <form
          onSubmit={onUnlink}
          noValidate
          aria-labelledby={titleId}
          className="flex flex-col gap-4 rounded-md border p-4"
        >
          <div className="flex flex-col gap-1">
            <h3 id={titleId} className="font-medium">
              Desvincular a conta Steam?
            </h3>
            <p className="text-sm text-muted-foreground">
              A análise de partidas deixa de reconhecer você até um novo vínculo. Confirme com a sua
              senha.
            </p>
          </div>
          <FormAlert message={errors.form} />
          <FormField
            label="Senha atual"
            name="current_password"
            type="password"
            autoComplete="current-password"
            required
            autoFocus
            error={errors.fields.current_password}
          />
          <div className="flex flex-wrap gap-2">
            <Button type="submit" variant="destructive" disabled={busy}>
              {busy && <LoaderCircleIcon className="animate-spin" />}
              Confirmar desvínculo
            </Button>
            <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
              Cancelar
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="outline" onClick={() => setConfirming(true)} className="self-start">
          Desvincular
        </Button>
      )}
    </div>
  )
}
