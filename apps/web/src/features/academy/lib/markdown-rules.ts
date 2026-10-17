/**
 * Regras do Markdown da Academy, espelho de `App\Modules\Academy\Content\MarkdownRules` (PHP).
 * ADR 0019, itens 5 a 7: a API só entrega conteúdo que passou por elas na importação; o
 * frontend confere de novo (defesa em profundidade) e descarta o bloco que não passar.
 */

/** `<` seguido de letra, `/`, `!` ou `?`: tags, comentários e autolinks. */
const HTML = /<[A-Za-z!/?]/
const IMAGE = /!\[/
const INLINE_LINK = /\]\(\s*([^)\s]*)/g
const REFERENCE_LINK = /^\s{0,3}\[[^\]]+\]:\s*(\S*)/gm
const FENCE = /^\s{0,3}(```|~~~)/
const ATX_H1 = /^\s{0,3}#(\s|$)/
const SETEXT_H1 = /^\s{0,3}=+\s*$/
const BLOCK_SYNTAX = /^\s{0,3}(#{1,6}\s|>|[-*+]\s|\d+[.)]\s|```|~~~)/m

/** Destinos de link permitidos: `https:`, `mailto:`, `#âncora` e caminhos internos (`/...`). */
export function isAllowedDestination(destination: string): boolean {
  return (
    destination.startsWith("https://") ||
    destination.startsWith("mailto:") ||
    destination.startsWith("#") ||
    // "//host" e "/\host" o navegador trata como outro domínio.
    (destination.startsWith("/") && !destination.startsWith("//") && !destination.startsWith("/\\"))
  )
}

function passesCommonRules(text: string): boolean {
  if (HTML.test(text) || IMAGE.test(text)) return false
  const destinations = [
    ...Array.from(text.matchAll(INLINE_LINK), (match) => match[1] ?? ""),
    ...Array.from(text.matchAll(REFERENCE_LINK), (match) => match[1] ?? ""),
  ]
  return destinations.every(isAllowedDestination)
}

/** Bloco `markdown`: regras comuns e títulos a partir de `##`. */
export function isSafeBlockMarkdown(text: string): boolean {
  if (!passesCommonRules(text)) return false

  let inFence = false
  let previous = ""
  for (const line of text.split(/\r\n|\r|\n/)) {
    if (FENCE.test(line)) {
      inFence = !inFence
      previous = ""
      continue
    }
    if (inFence) continue
    if (ATX_H1.test(line) || (previous.trim() !== "" && SETEXT_H1.test(line))) return false
    previous = line
  }
  return true
}

/** Markdown em linha (callout): regras comuns, sem títulos, listas, citações nem código em bloco. */
export function isSafeInlineMarkdown(text: string): boolean {
  return passesCommonRules(text) && !BLOCK_SYNTAX.test(text)
}
