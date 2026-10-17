<?php

namespace App\Modules\Academy\Content;

use App\Modules\Academy\Content\Blocks\BlockSchema;
use App\Modules\Academy\Content\Blocks\CalloutBlock;
use App\Modules\Academy\Content\Blocks\ImageBlock;
use App\Modules\Academy\Content\Blocks\MarkdownBlock;
use App\Modules\Academy\Content\Blocks\QuizRefBlock;
use App\Modules\Academy\Content\Blocks\VideoBlock;

/**
 * Conteúdo de uma aula, versão 1 (ADR 0016): `{version: 1, blocks: [...]}`.
 *
 * Mudança compatível mantém a versão; incompatível cria a versão 2 com migração explícita dos
 * dados (e um novo conjunto de schemas aqui).
 */
final class LessonContentSchema
{
    public const CURRENT_VERSION = 1;

    public const MAX_BLOCKS = 100;

    public const MAX_BYTES = 256 * 1024;

    /** @return array<string, BlockSchema> */
    public static function blocks(): array
    {
        $schemas = [new MarkdownBlock, new CalloutBlock, new VideoBlock, new ImageBlock, new QuizRefBlock];

        return array_combine(array_map(fn (BlockSchema $schema) => $schema->type(), $schemas), $schemas);
    }

    /**
     * Valida o conteúdo vindo do arquivo e devolve o conteúdo canônico, ou null com os erros.
     *
     * @return array{version: int, blocks: list<array{type: string, data: array<string, mixed>}>}|null
     */
    public static function normalize(mixed $content, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        if (! is_array($content) || array_is_list($content)) {
            $errors->add($location, 'precisa ter version e blocks');

            return null;
        }
        $before = $errors->count();
        Fields::rejectUnknown($content, ['version', 'blocks'], $location, $errors);
        $version = Fields::int($content, 'version', $location, $errors, 1, 1000);
        if ($version !== null && $version !== self::CURRENT_VERSION) {
            $errors->add(Fields::at($location, 'version'), 'versão de conteúdo não suportada; use '.self::CURRENT_VERSION);
        }
        $rawBlocks = Fields::listOfMaps($content, 'blocks', $location, $errors, 1, self::MAX_BLOCKS);
        if ($rawBlocks === null || $errors->count() !== $before) {
            return null;
        }

        $schemas = self::blocks();
        $blocks = [];
        $quizRefs = 0;
        foreach ($rawBlocks as $index => $raw) {
            $at = Fields::at($location, "blocks[{$index}]");
            $type = $raw['type'] ?? null;
            if (! is_string($type) || ! isset($schemas[$type])) {
                $errors->add(Fields::at($at, 'type'), 'tipo de bloco desconhecido; use '.implode(', ', array_keys($schemas)));

                continue;
            }
            unset($raw['type']);
            $quizRefs += $type === 'quiz_ref' ? 1 : 0;
            $data = $schemas[$type]->normalize($raw, $at, $context, $errors);
            if ($data !== null) {
                $blocks[] = ['type' => $type, 'data' => $data];
            }
        }

        $quizKey = $context->lessonQuizKey();
        if ($quizRefs > 1) {
            $errors->add(Fields::at($location, 'blocks'), 'no máximo um bloco quiz_ref por aula');
        } elseif ($quizKey !== null && $quizRefs === 0) {
            $errors->add(Fields::at($location, 'blocks'), "a aula declara o quiz {$quizKey}: inclua um bloco quiz_ref onde ele aparece");
        }

        if ($errors->count() !== $before) {
            return null;
        }

        $normalized = ['version' => self::CURRENT_VERSION, 'blocks' => $blocks];
        if (strlen(CanonicalJson::encode($normalized)) > self::MAX_BYTES) {
            $errors->add($location, 'conteúdo maior que 256 KB');

            return null;
        }

        return $normalized;
    }
}
