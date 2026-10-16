"use client"

import { useState } from "react"
import { LogOutIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import { LOGIN_PATH } from "@/lib/routes"

import { logout } from "../api/auth"
import { navigateAfterSessionChange } from "../lib/navigation"

/**
 * Sai e volta ao login. Mesmo se a API falhar (sessão já expirada, rede), o usuário vai para
 * o login: a área logada não deve continuar aberta depois de um clique em "Sair".
 */
export function LogoutButton() {
  const [pending, setPending] = useState(false)

  async function onClick() {
    setPending(true)
    try {
      await logout()
    } catch {
      // Ignorado de propósito: ver comentário acima.
    }
    navigateAfterSessionChange(LOGIN_PATH)
  }

  return (
    <Button variant="ghost" size="sm" onClick={onClick} disabled={pending}>
      <LogOutIcon />
      Sair
    </Button>
  )
}
