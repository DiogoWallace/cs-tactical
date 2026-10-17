import Markdown, { type Components } from "react-markdown"

import { cn } from "@/lib/utils"

import { isAllowedDestination } from "../lib/markdown-rules"

/**
 * Markdown das aulas, renderizado no servidor (ADR 0019, itens 5 a 7):
 *
 * - Sem HTML: `skipHtml` descarta qualquer HTML do texto (não há `rehype-raw`), e nenhum
 *   `dangerouslySetInnerHTML` é usado. O texto já chega validado (importador e schema Zod).
 * - Lista fechada de elementos: sem imagens (só o bloco `image`), tabelas ou HTML.
 * - Links só `https:`, `mailto:`, `#âncora` e caminhos internos; outros viram texto. Externos
 *   abrem em nova aba com `rel="noopener noreferrer"`.
 * - Títulos começam em `h2` (o `h1` é o título da aula); um `#` que escape vira `h2`.
 */

const BLOCK_ELEMENTS = [
  "p",
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "ul",
  "ol",
  "li",
  "blockquote",
  "pre",
  "code",
  "hr",
  "br",
  "strong",
  "em",
  "a",
]

const INLINE_ELEMENTS = ["p", "strong", "em", "code", "a", "br"]

function safeUrl(url: string): string | null {
  return isAllowedDestination(url) ? url : null
}

function isExternal(href: string): boolean {
  return href.startsWith("https://") || href.startsWith("mailto:")
}

const link: Components["a"] = ({ href, children }) => {
  if (!href) return <span>{children}</span>
  const external = isExternal(href)
  return (
    <a
      href={href}
      className="font-medium text-primary underline underline-offset-4 hover:no-underline focus-visible:rounded-sm"
      {...(external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
    >
      {children}
      {external && <span className="sr-only"> (abre em nova aba)</span>}
    </a>
  )
}

const inlineComponents: Components = {
  a: link,
  strong: ({ children }) => <strong className="font-semibold text-foreground">{children}</strong>,
  code: ({ children }) => (
    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.9em]">{children}</code>
  ),
}

const blockComponents: Components = {
  ...inlineComponents,
  h1: ({ children }) => <h2 className="mt-10 text-2xl font-semibold tracking-tight">{children}</h2>,
  h2: ({ children }) => (
    <h2 className="mt-10 scroll-mt-24 text-2xl font-semibold tracking-tight first:mt-0">
      {children}
    </h2>
  ),
  h3: ({ children }) => <h3 className="mt-8 text-xl font-semibold tracking-tight">{children}</h3>,
  h4: ({ children }) => <h4 className="mt-6 text-lg font-semibold">{children}</h4>,
  h5: ({ children }) => <h5 className="mt-6 font-semibold">{children}</h5>,
  h6: ({ children }) => <h6 className="mt-6 font-semibold text-muted-foreground">{children}</h6>,
  p: ({ children }) => <p className="mt-4 leading-7 first:mt-0">{children}</p>,
  ul: ({ children }) => (
    <ul className="mt-4 list-disc space-y-2 pl-6 marker:text-primary">{children}</ul>
  ),
  ol: ({ children }) => (
    <ol className="mt-4 list-decimal space-y-2 pl-6 marker:font-medium marker:text-primary">
      {children}
    </ol>
  ),
  li: ({ children }) => <li className="pl-1 leading-7">{children}</li>,
  blockquote: ({ children }) => (
    <blockquote className="mt-6 border-l-2 border-primary/60 pl-4 text-muted-foreground italic">
      {children}
    </blockquote>
  ),
  pre: ({ children }) => (
    <pre className="mt-6 overflow-x-auto rounded-lg border bg-muted/60 p-4 font-mono text-sm leading-6 [&_code]:bg-transparent [&_code]:p-0">
      {children}
    </pre>
  ),
  hr: () => <hr className="my-8 border-border" />,
}

type Props = { text: string; inline?: boolean; className?: string }

export function SafeMarkdown({ text, inline = false, className }: Props) {
  return (
    <div className={cn("text-base text-foreground", className)}>
      <Markdown
        skipHtml
        allowedElements={inline ? INLINE_ELEMENTS : BLOCK_ELEMENTS}
        unwrapDisallowed
        urlTransform={safeUrl}
        components={inline ? inlineComponents : blockComponents}
      >
        {text}
      </Markdown>
    </div>
  )
}
