<?php

namespace App\Modules\Academy\Content;

/**
 * Markdown seguro (ADR 0019, itens 5 a 7). Defesa em profundidade: o importador recusa o que
 * o renderizador também não deve interpretar.
 *
 * - Nenhum HTML: qualquer `<` seguido de letra, `/`, `!` ou `?` (tags, comentários, autolinks
 *   `<https://...>`) reprova, inclusive dentro de blocos de código. Para escrever "menor que",
 *   use `<` seguido de espaço ou dígito.
 * - Nenhuma imagem Markdown (`![...]`): imagens só pelo bloco `image`, com `alt` validado.
 * - Links só para `https:`, `mailto:`, caminhos internos (`/...`) e âncoras (`#...`).
 * - Blocos: títulos a partir de `##` (o `h1` é o título da aula).
 * - Em linha (callout, perguntas): sem títulos, listas, citações nem blocos de código.
 */
final class MarkdownRules
{
    private const HTML = '/<[A-Za-z!\/?]/';

    private const IMAGE = '/!\[/';

    private const INLINE_LINK = '/\]\(\s*([^)\s]*)/';

    private const REFERENCE_LINK = '/^\s{0,3}\[[^\]]+\]:\s*(\S*)/m';

    public static function block(string $text, string $location, ValidationErrors $errors): void
    {
        self::common($text, $location, $errors);

        $inFence = false;
        $previous = '';
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;
                $previous = '';

                continue;
            }
            if ($inFence) {
                continue;
            }
            $setextH1 = trim($previous) !== '' && preg_match('/^\s{0,3}=+\s*$/', $line) === 1;
            if (preg_match('/^\s{0,3}#(\s|$)/', $line) === 1 || $setextH1) {
                $errors->add($location, 'títulos começam em ## (o título da aula já é o h1)');

                return;
            }
            $previous = $line;
        }
    }

    public static function inline(string $text, string $location, ValidationErrors $errors): void
    {
        self::common($text, $location, $errors);

        if (preg_match('/^\s{0,3}(#{1,6}\s|>|[-*+]\s|\d+[.)]\s|```|~~~)/m', $text) === 1) {
            $errors->add($location, 'use só Markdown em linha (negrito, itálico, código e links), sem títulos, listas, citações ou blocos de código');
        }
    }

    private static function common(string $text, string $location, ValidationErrors $errors): void
    {
        if (preg_match(self::HTML, $text) === 1) {
            $errors->add($location, 'HTML não é permitido no Markdown');
        }
        if (preg_match(self::IMAGE, $text) === 1) {
            $errors->add($location, 'imagens no Markdown não são permitidas; use o bloco image');
        }

        preg_match_all(self::INLINE_LINK, $text, $inline);
        preg_match_all(self::REFERENCE_LINK, $text, $reference);
        foreach ([...$inline[1], ...$reference[1]] as $destination) {
            if (! self::allowedDestination($destination)) {
                $errors->add($location, "link não permitido ({$destination}); use https:, mailto:, /caminho ou #âncora");
            }
        }
    }

    private static function allowedDestination(string $destination): bool
    {
        return str_starts_with($destination, 'https://')
            || str_starts_with($destination, 'mailto:')
            || str_starts_with($destination, '#')
            // "//host" e "/\host" o navegador trata como outro domínio (mesma regra de safeInternalPath).
            || (str_starts_with($destination, '/') && ! str_starts_with($destination, '//') && ! str_starts_with($destination, '/\\'));
    }
}
