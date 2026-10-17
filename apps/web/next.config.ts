import type { NextConfig } from "next"

import {
  LESSON_HEADERS_SOURCE,
  lessonCspHeaders,
  PAGE_HEADERS_SOURCE,
  securityHeaders,
} from "./src/lib/security-headers"

/**
 * Endereço da API Laravel na rede interna do Docker. O navegador nunca o acessa
 * diretamente: as rotas abaixo encaminham /api e /sanctum pela mesma origem do
 * frontend (ADR 0003, docs/architecture/autenticacao.md).
 * Em `next build` o valor é fixado no build; em produção, defina-o no ambiente do build.
 */
const apiInternalUrl = process.env.API_INTERNAL_URL ?? "http://api:8080"

const nextConfig: NextConfig = {
  // O dev server roda no container com --hostname 0.0.0.0 e só libera "localhost" por padrão.
  // Sem isto, abrir http://127.0.0.1:3000 bloqueia o WebSocket do hot reload (/_next/hmr).
  allowedDevOrigins: ["127.0.0.1"],
  // Não anuncia o framework (X-Powered-By: Next.js).
  poweredByHeader: false,
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  // Headers de segurança das páginas (src/lib/security-headers.ts, docs/architecture/seguranca-http.md).
  async headers() {
    const isDev = process.env.NODE_ENV === "development"
    return [
      {
        source: PAGE_HEADERS_SOURCE,
        headers: securityHeaders(isDev),
      },
      // Só as aulas da Academy aceitam o player do YouTube em frame-src (ADR 0019, item 4).
      {
        source: LESSON_HEADERS_SOURCE,
        headers: lessonCspHeaders(isDev),
      },
    ]
  },
  async rewrites() {
    return {
      // beforeFiles: /api e /sanctum pertencem à API, mesmo que exista uma rota com esse nome no Next.
      beforeFiles: [
        { source: "/api/:path*", destination: `${apiInternalUrl}/api/:path*` },
        { source: "/sanctum/:path*", destination: `${apiInternalUrl}/sanctum/:path*` },
      ],
      afterFiles: [],
      fallback: [],
    }
  },
}

export default nextConfig
