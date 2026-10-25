import type { Metadata } from "next"

import { SteamLinkReturn } from "@/features/identity/components/steam-link-return"

export const metadata: Metadata = {
  title: "Conta Steam",
}

/** Volta do login da Steam (etapa M2). Protegida pelo proxy.ts como toda a área /painel. */
export default function SteamLinkReturnPage() {
  return (
    <div className="max-w-xl">
      <SteamLinkReturn />
    </div>
  )
}
