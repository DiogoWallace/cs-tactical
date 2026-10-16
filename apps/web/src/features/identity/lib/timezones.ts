let cachedTimezones: string[] | null = null

/**
 * Fusos horários IANA conhecidos pelo navegador, em ordem alfabética, com "UTC" sempre presente
 * (alguns navegadores não o incluem em `supportedValuesOf`). Use só no navegador: a lista do
 * Node pode ser diferente e quebraria a hidratação.
 */
export function listTimezones(): string[] {
  if (cachedTimezones) return cachedTimezones
  const supported =
    typeof Intl.supportedValuesOf === "function" ? Intl.supportedValuesOf("timeZone") : []
  cachedTimezones = Array.from(new Set(["UTC", ...supported])).sort((a, b) => a.localeCompare(b))
  return cachedTimezones
}
