import { notFound } from "next/navigation"

/**
 * Qualquer caminho inexistente sob /painel (já protegido pelo proxy.ts) cai aqui e mostra a
 * página "não encontrada" da área logada, com o cabeçalho, em vez da página genérica.
 */
export default function UnknownAppPage() {
  notFound()
}
