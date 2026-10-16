"use client"

import { useMemo, useState, useSyncExternalStore } from "react"
import Link from "next/link"
import { CircleCheckIcon, LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { FORGOT_PASSWORD_PATH, LOGIN_PATH } from "@/lib/routes"

import { resetPassword } from "../api/auth"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { FormAlert, FormField } from "./form-field"

/**
 * Nova senha a partir do link do e-mail: /redefinir-senha#token=...&email=...
 * Token e e-mail ficam no fragmento (#), que o navegador nunca envia ao servidor.
 */
export function ResetPasswordForm() {
  const hash = useSyncExternalStore(noSubscription, readHash, () => null)
  const link = useMemo(() => parseLink(hash), [hash])
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)

  if (hash === null) return null // Ainda no servidor: o fragmento só existe no navegador.

  if (!link) {
    return <InvalidLink message="Este link de redefinição está incompleto." />
  }

  if (done) {
    return (
      <div className="flex flex-col gap-4 text-sm">
        <p role="status" className="flex gap-2">
          <CircleCheckIcon className="mt-0.5 size-4 shrink-0 text-success" />
          Sua senha foi redefinida. Entre com a nova senha.
        </p>
        <Button asChild>
          <Link href={LOGIN_PATH}>Entrar</Link>
        </Button>
      </div>
    )
  }

  if (errors.fields.token) {
    return <InvalidLink message={errors.fields.token} />
  }

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!link) return
    const form = new FormData(event.currentTarget)

    setPending(true)
    setErrors(NO_ERRORS)
    try {
      await resetPassword({
        ...link,
        password: String(form.get("password") ?? ""),
        password_confirmation: String(form.get("password_confirmation") ?? ""),
      })
      setDone(true)
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setPending(false)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <p className="text-sm text-muted-foreground">
        Nova senha para <span className="font-medium text-foreground">{link.email}</span>.
      </p>
      <FormAlert message={errors.form} />
      <FormField
        label="Nova senha"
        name="password"
        type="password"
        autoComplete="new-password"
        required
        hint="De 8 a 72 caracteres."
        error={errors.fields.password}
      />
      <FormField
        label="Confirme a nova senha"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        error={errors.fields.password_confirmation}
      />
      <Button type="submit" disabled={pending}>
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Redefinir senha
      </Button>
    </form>
  )
}

function InvalidLink({ message }: { message: string }) {
  return (
    <div className="flex flex-col gap-4">
      <FormAlert message={message} />
      <Button asChild variant="outline">
        <Link href={FORGOT_PASSWORD_PATH}>Pedir um novo link</Link>
      </Button>
    </div>
  )
}

export function parseLink(hash: string | null): { token: string; email: string } | null {
  if (!hash) return null
  const params = new URLSearchParams(hash.replace(/^#/, ""))
  const token = params.get("token")
  const email = params.get("email")
  return token && email ? { token, email } : null
}

function readHash() {
  return window.location.hash
}

function noSubscription() {
  return () => {}
}
