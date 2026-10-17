<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\MarkdownRules;
use App\Modules\Academy\Content\ValidationErrors;

/** `callout`: destaque `info`, `tip` ou `warning`, com Markdown em linha. */
final class CalloutBlock implements BlockSchema
{
    public const VARIANTS = ['info', 'tip', 'warning'];

    public function type(): string
    {
        return 'callout';
    }

    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        $before = $errors->count();
        Fields::rejectUnknown($fields, ['variant', 'title', 'text'], $location, $errors);
        $variant = Fields::choice($fields, 'variant', self::VARIANTS, $location, $errors);
        $title = Fields::string($fields, 'title', $location, $errors, 1, 120, required: false);
        $text = Fields::string($fields, 'text', $location, $errors, 1, 2000);
        if ($text !== null) {
            MarkdownRules::inline($text, Fields::at($location, 'text'), $errors);
        }

        if ($errors->count() !== $before) {
            return null;
        }

        return ['variant' => $variant, 'title' => $title, 'text' => $text];
    }
}
