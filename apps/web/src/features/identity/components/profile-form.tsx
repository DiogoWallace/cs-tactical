"use client"

import { useState, useSyncExternalStore } from "react"
import { useRouter } from "next/navigation"
import { LoaderCircleIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { NativeSelect, Textarea } from "@/components/ui/native-select"

import { updateProfile } from "../api/account"
import { listCountries, type Country } from "../lib/countries"
import { NO_ERRORS, toFormErrors, type FormErrors } from "../lib/form-errors"
import { listTimezones } from "../lib/timezones"
import type { CurrentUser } from "../types"
import { FormAlert, FormField } from "./form-field"

/** Nome, apelido, país, fuso horário e bio. */
export function ProfileForm({ user }: { user: CurrentUser }) {
  const router = useRouter()
  // Listas só no navegador: no servidor viriam de outra base de idioma.
  const countries = useSyncExternalStore(noSubscription, listCountries, () => NO_COUNTRIES)
  const timezones = useSyncExternalStore(noSubscription, listTimezones, () => NO_TIMEZONES)

  const [country, setCountry] = useState(user.profile?.country_code ?? "")
  const [timezone, setTimezone] = useState(user.profile?.timezone ?? "UTC")
  const [errors, setErrors] = useState<FormErrors>(NO_ERRORS)
  const [pending, setPending] = useState(false)
  const [saved, setSaved] = useState(false)

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const text = (name: string) => String(form.get(name) ?? "")

    setPending(true)
    setSaved(false)
    setErrors(NO_ERRORS)
    try {
      await updateProfile({
        name: text("name"),
        nickname: text("nickname"),
        country_code: country || null,
        timezone,
        bio: text("bio").trim() || null,
      })
      setSaved(true)
      // Atualiza o cabeçalho (apelido) e o restante renderizado no servidor.
      router.refresh()
    } catch (error) {
      setErrors(toFormErrors(error))
    } finally {
      setPending(false)
    }
  }

  // O fuso atual entra na lista mesmo que o navegador não o conheça.
  const timezoneOptions =
    timezones.length > 0 && !timezones.includes(timezone) ? [timezone, ...timezones] : timezones

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormAlert message={errors.form} />
      {saved && (
        <p role="status" className="text-sm text-success">
          Perfil atualizado.
        </p>
      )}

      <FormField
        label="Nome"
        name="name"
        autoComplete="name"
        defaultValue={user.name}
        required
        error={errors.fields.name}
      />
      <FormField
        label="Apelido"
        name="nickname"
        autoComplete="username"
        defaultValue={user.profile?.nickname ?? ""}
        required
        hint="De 3 a 24 caracteres: letras, números, ponto, hífen ou sublinhado."
        error={errors.fields.nickname}
      />

      <SelectField label="País" id="country_code" error={errors.fields.country_code}>
        <NativeSelect
          id="country_code"
          value={country}
          onChange={(event) => setCountry(event.target.value)}
          aria-invalid={errors.fields.country_code ? true : undefined}
        >
          <option value="">Prefiro não informar</option>
          {countries.map((item) => (
            <option key={item.code} value={item.code}>
              {item.name}
            </option>
          ))}
        </NativeSelect>
      </SelectField>

      <SelectField label="Fuso horário" id="timezone" error={errors.fields.timezone}>
        <NativeSelect
          id="timezone"
          value={timezone}
          onChange={(event) => setTimezone(event.target.value)}
          aria-invalid={errors.fields.timezone ? true : undefined}
        >
          {timezoneOptions.length === 0 && <option value={timezone}>{timezone}</option>}
          {timezoneOptions.map((zone) => (
            <option key={zone} value={zone}>
              {zone.replaceAll("_", " ")}
            </option>
          ))}
        </NativeSelect>
      </SelectField>

      <SelectField label="Bio (opcional)" id="bio" error={errors.fields.bio}>
        <Textarea
          id="bio"
          name="bio"
          maxLength={500}
          defaultValue={user.profile?.bio ?? ""}
          aria-invalid={errors.fields.bio ? true : undefined}
        />
      </SelectField>

      <Button type="submit" disabled={pending} className="self-start">
        {pending && <LoaderCircleIcon className="animate-spin" />}
        Salvar perfil
      </Button>
    </form>
  )
}

function SelectField({
  label,
  id,
  error,
  children,
}: {
  label: string
  id: string
  error?: string
  children: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

const NO_COUNTRIES: Country[] = []
const NO_TIMEZONES: string[] = []

function noSubscription() {
  return () => {}
}
