"use client"

import { useState } from "react"
import { LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"

import { changePassword } from "../api/account"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { FormAlert, FormField } from "./form-field"

/** Troca de senha. Esta sessão continua; as outras são encerradas pela API. */
export function PasswordChangeForm() {
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)
  const [done, setDone] = useState(false)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const text = (name: string) => String(form.get(name) ?? "")

    setPending(true)
    setDone(false)
    setErrors(NO_ERRORS)
    try {
      await changePassword({
        current_password: text("current_password"),
        password: text("password"),
        password_confirmation: text("password_confirmation"),
      })
      formElement.reset()
      setDone(true)
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setPending(false)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.form} />
      {done && (
        <p role="status" className="text-sm text-success">
          Senha alterada. As outras sessões abertas foram encerradas.
        </p>
      )}
      <FormField
        label="Senha atual"
        name="current_password"
        type="password"
        autoComplete="current-password"
        required
        error={errors.fields.current_password}
      />
      <FormField
        label="Nova senha"
        name="password"
        type="password"
        autoComplete="new-password"
        required
        hint="De 8 a 72 caracteres, diferente da atual."
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
      <Button type="submit" disabled={pending} className="self-start">
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Alterar senha
      </Button>
    </form>
  )
}
