<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\MarkdownRules;
use App\Modules\Academy\Content\ValidationErrors;

/** `markdown`: texto corrido, sem HTML (ADR 0019). Arquivo `text` → banco `{text}`. */
final class MarkdownBlock implements BlockSchema
{
    public function type(): string
    {
        return 'markdown';
    }

    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        Fields::rejectUnknown($fields, ['text'], $location, $errors);
        $text = Fields::string($fields, 'text', $location, $errors, 1, 20000);
        if ($text === null) {
            return null;
        }

        $before = $errors->count();
        MarkdownRules::block($text, Fields::at($location, 'text'), $errors);

        return $errors->count() === $before ? ['text' => $text] : null;
    }
}
