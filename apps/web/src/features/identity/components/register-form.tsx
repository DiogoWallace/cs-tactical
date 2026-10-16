"use client"

import { useState, useSyncExternalStore } from "react"
import Link from "next/link"
import { LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { ApiError } from "@/lib/api/errors"
import { HOME_PATH, LOGIN_PATH } from "@/lib/routes"

import { register } from "../api/auth"
import { detectTimezone, listCountries, type Country } from "../lib/countries"
import { navigateAfterSessionChange } from "../lib/navigation"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { FormAlert, FormField } from "./form-field"

/**
 * Cadastro. O fuso horário vem do navegador; o país é opcional.
 * O papel na plataforma não é enviado: a API sempre cria a conta como `user`.
 */
export function RegisterForm() {
  // Lista e fuso só no navegador: no servidor viriam de outra base de idioma e de outro fuso.
  const countries = useSyncExternalStore(noSubscription, listCountries, () => NO_COUNTRIES)
  const timezone = useSyncExternalStore(noSubscription, detectTimezone, () => null)
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const text = (name: string) => String(form.get(name) ?? "")

    setPending(true)
    setErrors(NO_ERRORS)

    try {
      await register({
        name: text("name"),
        nickname: text("nickname"),
        email: text("email"),
        password: text("password"),
        password_confirmation: text("password_confirmation"),
        country_code: text("country_code") || null,
        timezone,
      })
    } catch (error) {
      if (error instanceof ApiError && error.code === "already_authenticated") {
        navigateAfterSessionChange(HOME_PATH)
        return
      }
      setErrors(toFormErrors(error))
      setPending(false)
      return
    }

    navigateAfterSessionChange(HOME_PATH)
  }

  const countryError = errors.fields.country_code

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.form} />

      <FormField label="Nome" name="name" autoComplete="name" required error={errors.fields.name} />
      <FormField
        label="Apelido"
        name="nickname"
        autoComplete="username"
        required
        hint="Como você aparece na plataforma. De 3 a 24 caracteres: letras, números, ponto, hífen ou sublinhado."
        error={errors.fields.nickname}
      />
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
        autoComplete="new-password"
        required
        hint="De 8 a 72 caracteres."
        error={errors.fields.password}
      />
      <FormField
        label="Confirme a senha"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        error={errors.fields.password_confirmation}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="country_code">País (opcional)</Label>
        <select
          id="country_code"
          name="country_code"
          defaultValue=""
          aria-invalid={countryError ? true : undefined}
          aria-describedby={countryError ? "country_code-error" : undefined}
          className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
        >
          <option value="">Prefiro não informar</option>
          {countries.map((country) => (
            <option key={country.code} value={country.code}>
              {country.name}
            </option>
          ))}
        </select>
        {countryError && (
          <p id="country_code-error" className="text-xs text-destructive">
            {countryError}
          </p>
        )}
      </div>

      <p className="text-xs text-muted-foreground">
        Fuso horário: {timezone ?? "UTC"}
        {errors.fields.timezone && (
          <span className="block text-destructive">{errors.fields.timezone}</span>
        )}
      </p>

      <Button type="submit" disabled={pending}>
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Criar conta
      </Button>

      <p className="text-center text-sm text-muted-foreground">
        Já tem conta?{" "}
        <Link href={LOGIN_PATH} className="font-medium text-primary hover:underline">
          Entrar
        </Link>
      </p>
    </form>
  )
}

const NO_COUNTRIES: Country[] = []

function noSubscription() {
  return () => {}
}
