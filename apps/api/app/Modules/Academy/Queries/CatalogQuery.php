<?php

namespace App\Modules\Academy\Queries;

use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Import\MediaStore;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consultas da API do catálogo (etapa 2.2). Único ponto de leitura dos controllers públicos
 * (ADR 0015, consequências): toda consulta passa pelo escopo `visible()` (regra R2), exceto no
 * modo editorial (`$editorial`), já autorizado por EditorialAccess.
 *
 * Número de consultas fixo por endpoint, sem N+1 (relações carregadas antes; o modo estrito
 * do Eloquent falha com lazy loading fora de produção).
 */
final class CatalogQuery
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, Course> */
    public function courses(?CourseLevel $level, bool $editorial): LengthAwarePaginator
    {
        $visible = $this->visibleUnless($editorial);

        /** @var LengthAwarePaginator<int, Course> $page */
        $page = Course::query()
            ->tap($visible)
            ->when($level, fn (Builder $query, CourseLevel $level) => $query->where('courses.level', $level->value))
            ->with('coverMedia')
            ->withCount([
                'modules as module_count' => $visible,
                'lessons as lesson_count' => $visible,
            ])
            ->withSum(['lessons as estimated_minutes' => $visible], 'estimated_minutes')
            ->orderBy('courses.position')
            ->orderBy('courses.id')
            ->paginate(self::PER_PAGE);

        return $page;
    }

    /** Curso com módulos e aulas em ordem (só os públicos, fora do modo editorial). */
    public function course(string $slug, bool $editorial): ?Course
    {
        $visible = $this->visibleUnless($editorial);

        return Course::query()
            ->tap($visible)
            ->where('courses.slug', $slug)
            ->with([
                'coverMedia',
                'modules' => fn (Relation $query) => $query->tap($visible)->orderBy('course_modules.position'),
                'modules.lessons' => fn (Relation $query) => $query->tap($visible)
                    ->withExists('activeQuiz as has_quiz')
                    ->orderBy('lessons.position'),
            ])
            ->first();
    }

    /** Aula de um curso, com o que a resposta precisa: módulo, quiz, imagens e vizinhas. */
    public function lesson(string $courseSlug, string $lessonSlug, bool $editorial): ?LessonPage
    {
        $visible = $this->visibleUnless($editorial);

        $course = Course::query()->tap($visible)->where('courses.slug', $courseSlug)->first();
        if ($course === null) {
            return null;
        }

        $lesson = Lesson::query()
            ->tap($visible)
            ->where('lessons.course_id', $course->id)
            ->where('lessons.slug', $lessonSlug)
            ->with([
                'module',
                'activeQuiz' => fn (Relation $query) => $query->withCount([
                    'questions as question_count' => fn (Builder $questions) => $questions->whereNull('retired_at'),
                ]),
            ])
            ->first();
        if ($lesson === null) {
            return null;
        }
        $lesson->setRelation('course', $course);

        // Ordem do curso: posição do módulo, depois da aula. Só slug e título das vizinhas.
        $sequence = Lesson::query()
            ->tap($visible)
            ->where('lessons.course_id', $course->id)
            ->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->orderBy('course_modules.position')
            ->orderBy('lessons.position')
            ->get(['lessons.id', 'lessons.slug', 'lessons.title'])
            ->values();
        $index = $sequence->search(fn (Lesson $item) => $item->id === $lesson->id);

        /** @var Quiz|null $quiz */
        $quiz = $lesson->activeQuiz;

        return new LessonPage(
            lesson: $lesson,
            media: $this->mediaFor($lesson),
            quiz: $quiz,
            previous: $index === false || $index === 0 ? null : $sequence->get($index - 1),
            next: $index === false ? null : $sequence->get($index + 1),
        );
    }

    /** Registro de uma imagem pelo nome do arquivo (`{sha256}.{ext}`), se o caminho confere. */
    public function media(string $sha256, string $extension): ?Media
    {
        return Media::query()
            ->where('sha256', $sha256)
            ->where('path', MediaStore::pathFor($sha256, $extension))
            ->first();
    }

    /**
     * A imagem é pública: capa de um curso público ou bloco `image` de uma aula pública. Mídia
     * de rascunho, de conteúdo arquivado ou sem referência não é servida a visitantes.
     */
    public function mediaIsPublic(Media $media): bool
    {
        if (Course::query()->visible()->where('courses.cover_media_id', $media->id)->exists()) {
            return true;
        }

        return Lesson::query()
            ->visible()
            ->whereRaw(
                "jsonb_path_exists(lessons.content, '\$.blocks[*] ? (@.type == \"image\" && @.data.media == \$sha)', jsonb_build_object('sha', ?::text))",
                [$media->sha256],
            )
            ->exists();
    }

    /** @return array<string, Media> imagens citadas pelos blocos `image`, por sha256 */
    private function mediaFor(Lesson $lesson): array
    {
        $hashes = [];
        foreach ($lesson->content['blocks'] as $block) {
            if ($block['type'] === 'image' && is_string($block['data']['media'] ?? null)) {
                $hashes[] = $block['data']['media'];
            }
        }
        if ($hashes === []) {
            return [];
        }

        return Media::query()->whereIn('sha256', array_unique($hashes))->get()->keyBy('sha256')->all();
    }

    /**
     * Aplica `visible()` fora do modo editorial. Serve para a consulta principal e para as
     * relações e contagens (Builder ou Relation de cursos, módulos e aulas).
     *
     * @return \Closure(Builder<Course>|Builder<CourseModule>|Builder<Lesson>|Relation<*, *, *>): void
     */
    private function visibleUnless(bool $editorial): \Closure
    {
        return function (Builder|Relation $query) use ($editorial): void {
            if (! $editorial) {
                $query->scopes(['visible']);
            }
        };
    }
}
