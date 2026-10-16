import "server-only"

import { z } from "zod"

/**
 * Variáveis de ambiente do servidor, validadas na primeira leitura.
 * Uma configuração inválida falha cedo, com mensagem clara, em vez de quebrar uma página.
 */
const serverEnvSchema = z.object({
  API_INTERNAL_URL: z.url().default("http://api:8080"),
  NEXT_PUBLIC_APP_URL: z.url().default("http://localhost:3000"),
})

export type ServerEnv = z.infer<typeof serverEnvSchema>

let cached: ServerEnv | undefined

export function serverEnv(): ServerEnv {
  cached ??= serverEnvSchema.parse({
    API_INTERNAL_URL: process.env.API_INTERNAL_URL,
    NEXT_PUBLIC_APP_URL: process.env.NEXT_PUBLIC_APP_URL,
  })
  return cached
}
