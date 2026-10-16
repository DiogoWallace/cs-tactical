"use client"

import { useState } from "react"
import { LoaderCircleIcon, MailCheckIcon } from "lucide-react"

import { Button } from "@/components/ui/button"

import { cancelEmailChange, requestEmailChange } from "../api/account"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import type { CurrentUser } from "../types"
import { FormAlert, FormField } from "./form-field"

/**
 * Troca de e-mail com confirmação no novo endereço (ADR 0011). O e-mail atual continua
 * valendo até o clique no link; um pedido pendente pode ser cancelado.
 */
export function EmailChangeForm({ user }: { user: CurrentUser }) {
  const [pendingEmail, setPendingEmail] = useState(user.pending_email)
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [busy, setBusy] = useState(false)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)

    setBusy(true)
    setErrors(NO_ERRORS)
    try {
      const updated = await requestEmailChange(
        String(form.get("email") ?? ""),
        String(form.get("current_password") ?? ""),
      )
      setPendingEmail(updated.pending_email)
      formElement.reset()
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setBusy(false)
    }
  }

  async function onCancel() {
    setBusy(true)
    setErrors(NO_ERRORS)
    try {
      setPendingEmail((await cancelEmailChange()).pending_email)
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm">
        E-mail atual: <span className="font-medium break-all">{user.email}</span>
      </p>

      {pendingEmail && (
        <div
          role="status"
          className="flex flex-col items-start gap-2 rounded-md border px-3 py-2 text-sm"
        >
          <p className="flex gap-2">
            <MailCheckIcon className="mt-0.5 size-4 shrink-0 text-primary" />
            <span>
              Enviamos um link para <span className="font-medium break-all">{pendingEmail}</span>. A
              troca só acontece depois do clique; o link vale por 60 minutos.
            </span>
          </p>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
            Cancelar troca
          </Button>
        </div>
      )}

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <FormAlert message={errors.form} />
        <FormField
          label="Novo e-mail"
          name="email"
          type="email"
          autoComplete="email"
          required
          error={errors.fields.email}
        />
        <FormField
          label="Senha atual"
          name="current_password"
          type="password"
          autoComplete="current-password"
          required
          error={errors.fields.current_password}
        />
        <Button type="submit" disabled={busy} className="self-start">
          {busy && <LoaderCircleIcon className="animate-spin" />}
          Enviar link de confirmação
        </Button>
      </form>
    </div>
  )
}
