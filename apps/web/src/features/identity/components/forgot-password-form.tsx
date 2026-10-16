"use client"

import { useState } from "react"
import Link from "next/link"
import { LoaderCircleIcon, MailCheckIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { LOGIN_PATH } from "@/lib/routes"

import { requestPasswordReset } from "../api/auth"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { FormAlert, FormField } from "./form-field"

/**
 * "Esqueci minha senha". A resposta é a mesma com ou sem conta para o e-mail, então a tela
 * de confirmação também é a mesma.
 */
export function ForgotPasswordForm() {
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)
  const [sentMessage, setSentMessage] = useState<string | null>(null)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const email = String(new FormData(event.currentTarget).get("email") ?? "")

    setPending(true)
    setErrors(NO_ERRORS)
    try {
      setSentMessage(await requestPasswordReset(email))
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setPending(false)
    }
  }

  if (sentMessage) {
    return (
      <div className="flex flex-col gap-4 text-sm">
        <p role="status" className="flex gap-2">
          <MailCheckIcon className="mt-0.5 size-4 shrink-0 text-primary" />
          <span>{sentMessage} O link vale por 60 minutos. Confira também a caixa de spam.</span>
        </p>
        <Link href={LOGIN_PATH} className="font-medium text-primary hover:underline">
          Voltar para o login
        </Link>
      </div>
    )
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.form} />
      <FormField
        label="E-mail"
        name="email"
        type="email"
        autoComplete="email"
        required
        error={errors.fields.email}
      />
      <Button type="submit" disabled={pending}>
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Enviar link
      </Button>
      <p className="text-center text-sm text-muted-foreground">
        Lembrou a senha?{" "}
        <Link href={LOGIN_PATH} className="font-medium text-primary hover:underline">
          Entrar
        </Link>
      </p>
    </form>
  )
}
