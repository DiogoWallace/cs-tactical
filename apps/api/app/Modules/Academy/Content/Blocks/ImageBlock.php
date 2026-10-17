<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\ValidationErrors;

/**
 * `image`: arquivo da pasta `media/` do curso, referenciado no banco pelo sha256 (ADR 0019).
 * `alt` obrigatório, exceto em imagem decorativa (`decorative: true`, com `alt` vazio).
 */
final class ImageBlock implements BlockSchema
{
    public function type(): string
    {
        return 'image';
    }

    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        $before = $errors->count();
        Fields::rejectUnknown($fields, ['src', 'alt', 'decorative', 'caption'], $location, $errors);
        $src = Fields::string($fields, 'src', $location, $errors, 1, 120);
        $decorative = Fields::bool($fields, 'decorative', $location, $errors, default: false);
        $caption = Fields::string($fields, 'caption', $location, $errors, 1, 300, required: false);

        $alt = '';
        if ($decorative === true) {
            if (($fields['alt'] ?? '') !== '') {
                $errors->add(Fields::at($location, 'alt'), 'imagem decorativa tem alt vazio');
            }
        } else {
            $alt = Fields::string($fields, 'alt', $location, $errors, 1, 300) ?? '';
        }

        $sha = $src === null ? null : $context->mediaSha($src, Fields::at($location, 'src'), $errors);

        if ($errors->count() !== $before || $sha === null) {
            return null;
        }

        return ['media' => $sha, 'alt' => $alt, 'decorative' => $decorative, 'caption' => $caption];
    }
}
