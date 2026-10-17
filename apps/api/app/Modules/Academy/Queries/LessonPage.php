<?php

namespace App\Modules\Academy\Queries;

use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;

/**
 * Tudo o que a resposta de uma aula precisa, já carregado (CatalogQuery::lesson). `quiz` traz
 * `question_count` (perguntas ativas); `previous`/`next` têm só id, slug e título.
 */
final readonly class LessonPage
{
    /** @param  array<string, Media>  $media  imagens dos blocos, por sha256 */
    public function __construct(
        public Lesson $lesson,
        public array $media,
        public ?Quiz $quiz,
        public ?Lesson $previous,
        public ?Lesson $next,
    ) {}
}
