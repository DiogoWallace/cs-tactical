<?php

namespace App\Modules\Academy\Http\Resources;

use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Queries\LessonPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

/**
 * Aula com o conteúdo resolvido (docs/api/academy-v1.md):
 *
 * - `image`: o sha256 guardado no banco vira `url` pública com as dimensões do registro;
 * - `quiz_ref`: resumo do quiz ativo da aula, **sem perguntas, opções nem gabarito** (ADR 0018);
 * - os demais blocos saem com a lista fechada de campos do schema v1.
 *
 * Bloco de tipo desconhecido, ou que cite imagem ou quiz inexistente, não sai (e vai para o
 * log): a importação impede esses casos, então eles indicam dado inconsistente no banco.
 *
 * @property LessonPage $resource
 */
class LessonResource extends JsonResource
{
    public function __construct(LessonPage $page)
    {
        parent::__construct($page);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        $lesson = $page->lesson;
        $editorial = EditorialAccess::requested($request);

        return [
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            ...($editorial ? ['status' => $lesson->status->value] : []),
            'summary' => $lesson->summary,
            'estimated_minutes' => $lesson->estimated_minutes,
            'course' => [
                'slug' => $lesson->course->slug,
                'title' => $lesson->course->title,
                ...($editorial ? ['status' => $lesson->course->status->value] : []),
            ],
            'module' => [
                'slug' => $lesson->module->slug,
                'title' => $lesson->module->title,
                ...($editorial ? ['status' => $lesson->module->status->value] : []),
            ],
            'content' => [
                'version' => $lesson->content['version'],
                'blocks' => $this->blocks($page),
            ],
            'content_hash' => $lesson->content_hash,
            'previous' => $this->neighbour($page->previous),
            'next' => $this->neighbour($page->next),
        ];
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    private function blocks(LessonPage $page): array
    {
        $blocks = [];
        foreach ($page->lesson->content['blocks'] as $index => $block) {
            $data = $this->blockData($block['type'], $block['data'], $page);
            if ($data === null) {
                Log::warning('Academy: bloco omitido da resposta da aula', [
                    'lesson_id' => $page->lesson->id,
                    'block' => $index,
                    'type' => $block['type'],
                ]);

                continue;
            }
            $blocks[] = ['type' => $block['type'], 'data' => $data];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function blockData(string $type, array $data, LessonPage $page): ?array
    {
        return match ($type) {
            'markdown' => ['text' => $data['text'] ?? null],
            'callout' => [
                'variant' => $data['variant'] ?? null,
                'title' => $data['title'] ?? null,
                'text' => $data['text'] ?? null,
            ],
            'video' => [
                'provider' => $data['provider'] ?? null,
                'video_id' => $data['video_id'] ?? null,
                'title' => $data['title'] ?? null,
                'start_seconds' => $data['start_seconds'] ?? null,
            ],
            'image' => $this->image($data, $page),
            'quiz_ref' => $this->quizRef($data, $page),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function image(array $data, LessonPage $page): ?array
    {
        $media = is_string($data['media'] ?? null) ? ($page->media[$data['media']] ?? null) : null;
        if ($media === null) {
            return null;
        }

        return [
            'url' => MediaUrl::of($media),
            'alt' => $data['alt'] ?? '',
            'caption' => $data['caption'] ?? null,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * Só os números públicos do quiz. Perguntas, opções, `is_correct` e explicações nunca
     * saem daqui: chegam só pela API de tentativas (etapa 2.5), com as regras R20.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function quizRef(array $data, LessonPage $page): ?array
    {
        $quiz = $page->quiz;
        if ($quiz === null || $quiz->key !== ($data['quiz'] ?? null)) {
            return null;
        }

        return [
            'quiz' => $quiz->key,
            'title' => $quiz->title,
            'question_count' => (int) $quiz->getAttribute('question_count'),
            'max_attempts' => $quiz->max_attempts,
            'pass_percent' => $quiz->pass_percent,
        ];
    }

    /** @return array{slug: string, title: string}|null */
    private function neighbour(?Lesson $lesson): ?array
    {
        return $lesson === null ? null : ['slug' => $lesson->slug, 'title' => $lesson->title];
    }
}
