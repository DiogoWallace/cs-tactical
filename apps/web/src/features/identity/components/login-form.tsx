"use client"

import { useState } from "react"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { ApiError } from "@/lib/api/errors"
import { afterLoginPath, REGISTER_PATH } from "@/lib/routes"

import { login } from "../api/auth"
import { navigateAfterSessionChange } from "../lib/navigation"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { FormAlert, FormField } from "./form-field"

/**
 * Login. Depois de entrar, volta para `?next=` (só caminhos internos) ou vai ao painel.
 * Credenciais inválidas aparecem como mensagem geral: a API não diz qual dos dois campos errou.
 */
export function LoginForm() {
  const next = useSearchParams().get("next")
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    setPending(true)
    setErrors(NO_ERRORS)

    try {
      await login({
        email: String(form.get("email") ?? ""),
        password: String(form.get("password") ?? ""),
        remember: form.get("remember") === "on",
      })
    } catch (error) {
      // Já autenticado (outra aba, por exemplo): segue para o destino.
      if (error instanceof ApiError && error.code === "already_authenticated") {
        navigateAfterSessionChange(afterLoginPath(next))
        return
      }

      const { fields, form: formError } = toFormErrors(error)
      // A API devolve "E-mail ou senha incorretos." no campo email: mostra como erro geral.
      const { email: credentialsError, ...otherFields } = fields
      setErrors({ fields: otherFields, form: formError ?? credentialsError ?? null })
      setPending(false)
      return
    }

    navigateAfterSessionChange(afterLoginPath(next))
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
      <FormField
        label="Senha"
        name="password"
        type="password"
        autoComplete="current-password"
        required
        error={errors.fields.password}
      />

      <label className="flex items-center gap-2 text-sm text-muted-foreground">
        <input type="checkbox" name="remember" className="size-4 accent-primary" />
        Manter conectado por 30 dias
      </label>

      <Button type="submit" disabled={pending}>
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Entrar
      </Button>

      <p className="text-center text-sm text-muted-foreground">
        Ainda não tem conta?{" "}
        <Link href={REGISTER_PATH} className="font-medium text-primary hover:underline">
          Criar conta
        </Link>
      </p>
    </form>
  )
}
