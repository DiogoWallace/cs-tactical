<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\ValidationErrors;
use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Import\Draft\ContentPlan;
use App\Modules\Academy\Import\Draft\CourseDraft;
use App\Modules\Academy\Import\Draft\LessonDraft;
use App\Modules\Academy\Import\Draft\ModuleDraft;
use App\Modules\Academy\Import\Draft\OptionDraft;
use App\Modules\Academy\Import\Draft\QuestionDraft;
use App\Modules\Academy\Import\Draft\QuizDraft;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fases (b) e (c) da importação (ADR 0016), depois da validação dos arquivos (ContentParser):
 *
 * 0. Verificações contra o banco, só leitura: transições de status, slug e posição já usados
 *    por itens ausentes dos arquivos, key de quiz que pertence a outra aula.
 * 1. Grava as imagens no disco `academy` (fora da transação; nomes por hash, imutáveis).
 * 2. Uma transação: registra as mídias, cria/atualiza pela `key` (o slug pode mudar sem criar
 *    outro registro), sincroniza quizzes e, com `--archive-missing`, arquiva os ausentes.
 *
 * Idempotente: sem mudança nos arquivos, nenhuma escrita no banco nem no disco. Nunca apaga
 * curso, módulo, aula nem mídia; ausentes só são listados (ou arquivados, se pedido).
 * Quem chama segura o ContentLock.
 */
final class ContentImporter
{
    /** Desloca posições ativas antes de reordenar perguntas/opções (índices únicos parciais). */
    private const POSITION_PARKING = 1000000;

    private Carbon $now;

    public function __construct(
        private readonly MediaStore $store,
        private readonly QuizUsage $usage,
    ) {}

    /** @throws ContentValidationException */
    public function import(ContentPlan $plan, bool $dryRun = false, bool $archiveMissing = false): ImportReport
    {
        $errors = new ValidationErrors;
        $this->preflight($plan, $errors);
        if (! $errors->isEmpty()) {
            throw new ContentValidationException($errors);
        }

        $report = new ImportReport($dryRun);
        $this->now = Carbon::now();

        if (! $dryRun) {
            foreach ($plan->images as $image) {
                $report->filesWritten += $this->store->put($image) ? 1 : 0;
            }
        }

        try {
            DB::transaction(function () use ($plan, $dryRun, $archiveMissing, $report) {
                // Explícito: dentro de uma transação externa (testes), um IMMEDIATE anterior valeria aqui.
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
                $this->apply($plan, $archiveMissing, $report, $dryRun);
                // As unicidades adiáveis (posição, slug) reprovam aqui, dentro do try, e não no COMMIT.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                if ($dryRun) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // Simulação: tudo desfeito; o relatório mostra o que seria feito.
        }

        return $report;
    }

    private function preflight(ContentPlan $plan, ValidationErrors $errors): void
    {
        /** @var Collection<string, Course> $courses */
        $courses = Course::query()->get()->keyBy('key');
        $planKeys = array_map(fn (CourseDraft $draft) => $draft->key, $plan->courses);
        $absentCourses = self::without($courses, $planKeys);

        foreach ($plan->courses as $draft) {
            $course = $courses->get($draft->key);
            $this->checkTransition($course, $draft->status, $draft->location, $errors);
            $this->checkAbsentCollision($absentCourses, $draft, 'curso', $errors);
            if ($course !== null) {
                $this->preflightCourse($course, $draft, $errors);
            }
        }

        $quizDrafts = [];
        $planLessons = [];
        foreach ($plan->courses as $course) {
            foreach ($course->modules as $module) {
                foreach ($module->lessons as $lesson) {
                    $planLessons["{$course->key}/{$lesson->key}"] = [$course->key, $lesson];
                    if ($lesson->quiz !== null) {
                        $quizDrafts[$lesson->quiz->key] = [$course->key, $lesson];
                    }
                }
            }
        }

        $this->checkMovedLessons($planLessons, $errors);
        $quizzes = Quiz::query()->whereIn('key', array_keys($quizDrafts))->with('lesson.course')->get();
        foreach ($quizzes as $quiz) {
            [$courseKey, $lesson] = $quizDrafts[$quiz->key];
            if ($quiz->lesson->course->key !== $courseKey || $quiz->lesson->key !== $lesson->key) {
                $errors->add(Fields::at((string) $lesson->quiz?->location, 'key'),
                    "key já pertence ao quiz da aula {$quiz->lesson->course->key}/{$quiz->lesson->key}");
            }
        }
    }

    /**
     * Aula nova num curso cuja `key` existe em outro curso e sumiu dos arquivos de lá: é uma aula
     * movida entre cursos. A `key` é única por curso, então isso criaria outra aula e deixaria o
     * progresso na antiga; recusado (ADR 0016). Mesma `key` em dois cursos, ambas nos arquivos, é
     * permitido: são aulas diferentes.
     *
     * @param  array<string, array{0: string, 1: LessonDraft}>  $planLessons  "curso/aula" → [curso, aula]
     */
    private function checkMovedLessons(array $planLessons, ValidationErrors $errors): void
    {
        $existing = Lesson::query()->join('courses', 'courses.id', '=', 'lessons.course_id')
            ->whereIn('lessons.key', array_unique(array_map(fn (array $entry) => $entry[1]->key, $planLessons)))
            ->get(['lessons.key as lesson_key', 'courses.key as course_key'])
            ->map(fn (Lesson $row) => $row->getAttribute('course_key').'/'.$row->getAttribute('lesson_key'))
            ->all();

        foreach ($planLessons as $id => [$courseKey, $lesson]) {
            if (in_array($id, $existing, true)) {
                continue;
            }
            foreach ($existing as $other) {
                if (str_ends_with($other, "/{$lesson->key}") && ! isset($planLessons[$other])) {
                    $errors->add(Fields::at($lesson->location, 'key'),
                        "a aula {$other} saiu dos arquivos daquele curso: mover aula entre cursos não é permitido (use outra key e arquive a antiga)");
                }
            }
        }
    }

    private function preflightCourse(Course $course, CourseDraft $draft, ValidationErrors $errors): void
    {
        /** @var Collection<string, CourseModule> $modules */
        $modules = CourseModule::query()->where('course_id', $course->id)->get()->keyBy('key');
        $absentModules = self::without($modules, array_map(fn (ModuleDraft $module) => $module->key, $draft->modules));

        /** @var Collection<string, Lesson> $lessons */
        $lessons = Lesson::query()->where('course_id', $course->id)->get()->keyBy('key');
        $lessonKeys = [];
        foreach ($draft->modules as $module) {
            foreach ($module->lessons as $lesson) {
                $lessonKeys[] = $lesson->key;
            }
        }
        $absentLessons = self::without($lessons, $lessonKeys);

        foreach ($draft->modules as $moduleDraft) {
            $module = $modules->get($moduleDraft->key);
            $this->checkTransition($module, $moduleDraft->status, $moduleDraft->location, $errors);
            $this->checkAbsentCollision($absentModules, $moduleDraft, 'módulo', $errors);

            foreach ($moduleDraft->lessons as $lessonDraft) {
                $this->checkTransition($lessons->get($lessonDraft->key), $lessonDraft->status, $lessonDraft->location, $errors);
                $this->checkAbsentCollision($absentLessons, $lessonDraft, 'aula', $errors, position: false);
                $sameModule = $module === null ? collect() : $absentLessons->where('module_id', $module->id);
                foreach ($sameModule as $absent) {
                    if ($absent->position === $lessonDraft->position) {
                        $errors->add(Fields::at($lessonDraft->location, 'position'),
                            "posição já usada pela aula {$absent->key}, que não está nos arquivos");
                    }
                }
            }
        }
    }

    /**
     * Itens cuja `key` não está em `$keys`. (O `except()` da coleção do Eloquent filtra pela
     * chave primária, não pela chave do `keyBy`.)
     *
     * @template TModel of Model
     *
     * @param  Collection<string, TModel>  $items
     * @param  list<string>  $keys
     * @return Collection<string, TModel>
     */
    private static function without(Collection $items, array $keys): Collection
    {
        return $items->whereNotIn('key', $keys);
    }

    private function checkTransition(Course|CourseModule|Lesson|null $model, PublicationStatus $next, string $location, ValidationErrors $errors): void
    {
        if ($model !== null && ! $model->status->canTransitionTo($next)) {
            $errors->add(Fields::at($location, 'status'),
                "não pode passar de {$model->status->value} para {$next->value} (conteúdo publicado só pode ser arquivado)");
        }
    }

    /**
     * Itens ausentes dos arquivos continuam no banco com o slug e a posição deles.
     *
     * @param  Collection<string, Course>|Collection<string, CourseModule>|Collection<string, Lesson>  $absent
     */
    private function checkAbsentCollision(Collection $absent, CourseDraft|ModuleDraft|LessonDraft $draft, string $label, ValidationErrors $errors, bool $position = true): void
    {
        foreach ($absent as $item) {
            if ($item->slug === $draft->slug) {
                $errors->add(Fields::at($draft->location, 'slug'), "slug já usado por {$label} {$item->key}, que não está nos arquivos");
            }
            if ($position && $item->position === $draft->position) {
                $errors->add(Fields::at($draft->location, 'position'), "posição já usada por {$label} {$item->key}, que não está nos arquivos");
            }
        }
    }

    private function apply(ContentPlan $plan, bool $archiveMissing, ImportReport $report, bool $dryRun): void
    {
        $mediaIds = $this->registerMedia($plan, $report, $dryRun);

        /** @var Collection<string, Course> $courses */
        $courses = Course::query()->get()->keyBy('key');
        foreach ($plan->courses as $draft) {
            $course = $courses->get($draft->key) ?? new Course;
            $course->forceFill([
                'key' => $draft->key,
                'slug' => $draft->slug,
                'title' => $draft->title,
                'summary' => $draft->summary,
                'level' => $draft->level,
                'position' => $draft->position,
                'cover_media_id' => $draft->coverSha === null ? null : $mediaIds[$draft->coverSha],
            ]);
            $this->setStatus($course, $draft->status);
            $this->persist($course, 'courses', $report);

            $this->applyModules($course, $draft, $archiveMissing, $report);
        }

        foreach (self::without($courses, array_map(fn (CourseDraft $draft) => $draft->key, $plan->courses)) as $absent) {
            $this->absent($absent, "curso {$absent->key}", $archiveMissing, $report);
        }
    }

    private function applyModules(Course $course, CourseDraft $draft, bool $archiveMissing, ImportReport $report): void
    {
        /** @var Collection<string, CourseModule> $modules */
        $modules = CourseModule::query()->where('course_id', $course->id)->get()->keyBy('key');
        /** @var Collection<string, Lesson> $lessons */
        $lessons = Lesson::query()->where('course_id', $course->id)->get()->keyBy('key');
        $lessonKeys = [];

        foreach ($draft->modules as $moduleDraft) {
            $module = $modules->get($moduleDraft->key) ?? new CourseModule;
            $module->forceFill([
                'course_id' => $course->id,
                'key' => $moduleDraft->key,
                'slug' => $moduleDraft->slug,
                'title' => $moduleDraft->title,
                'summary' => $moduleDraft->summary,
                'position' => $moduleDraft->position,
            ]);
            $this->setStatus($module, $moduleDraft->status);
            $this->persist($module, 'modules', $report);

            foreach ($moduleDraft->lessons as $lessonDraft) {
                $lessonKeys[] = $lessonDraft->key;
                $lesson = $lessons->get($lessonDraft->key) ?? new Lesson;
                $lesson->forceFill([
                    'course_id' => $course->id,
                    'module_id' => $module->id,
                    'key' => $lessonDraft->key,
                    'slug' => $lessonDraft->slug,
                    'title' => $lessonDraft->title,
                    'summary' => $lessonDraft->summary,
                    'position' => $lessonDraft->position,
                    'estimated_minutes' => $lessonDraft->estimatedMinutes,
                ]);
                // jsonb reordena chaves: a comparação é pelo hash do JSON canônico, não pelo array.
                if ($lesson->content_hash !== $lessonDraft->contentHash) {
                    $lesson->forceFill([
                        'content' => $lessonDraft->content,
                        'content_version' => $lessonDraft->content['version'],
                        'content_hash' => $lessonDraft->contentHash,
                    ]);
                }
                $this->setStatus($lesson, $lessonDraft->status);
                $this->persist($lesson, 'lessons', $report);

                $this->syncQuiz($lesson, $lessonDraft->quiz, $report);
            }
        }

        foreach (self::without($modules, array_map(fn (ModuleDraft $module) => $module->key, $draft->modules)) as $absent) {
            $this->absent($absent, "módulo {$course->key}/{$absent->key}", $archiveMissing, $report);
        }
        foreach (self::without($lessons, $lessonKeys) as $absent) {
            $this->absent($absent, "aula {$course->key}/{$absent->key}", $archiveMissing, $report);
        }
    }

    /** @return array<string, string> sha256 → id em academy_media */
    private function registerMedia(ContentPlan $plan, ImportReport $report, bool $dryRun): array
    {
        $ids = Media::query()->whereIn('sha256', array_keys($plan->images))->pluck('id', 'sha256')->all();

        foreach ($plan->images as $sha => $image) {
            if (isset($ids[$sha])) {
                continue;
            }
            // Nenhuma linha aponta para arquivo que não existe (a simulação não grava arquivos).
            if (! $dryRun && ! $this->store->disk()->exists($image->path())) {
                throw new RuntimeException("Arquivo {$image->path()} não foi gravado; importação cancelada.");
            }
            $media = new Media;
            $media->forceFill([
                'sha256' => $sha,
                'path' => $image->path(),
                'mime' => $image->mime,
                'bytes' => $image->bytes,
                'width' => $image->width,
                'height' => $image->height,
            ])->save();
            $ids[$sha] = $media->id;
            $report->mediaCreated++;
        }

        return $ids;
    }

    private function setStatus(Course|CourseModule|Lesson $model, PublicationStatus $status): void
    {
        $model->status = $status;
        if ($status === PublicationStatus::Published) {
            $model->published_at ??= $this->now;
            $model->archived_at = null;
        } elseif ($status === PublicationStatus::Archived) {
            $model->archived_at ??= $this->now;
        }
    }

    /** @param  'courses'|'modules'|'lessons'|'quizzes'  $entity */
    private function persist(Model $model, string $entity, ImportReport $report): void
    {
        $created = ! $model->exists;
        $changed = $created || $model->isDirty();
        if ($changed) {
            $model->save();
        }
        $report->count($entity, $created, $changed);
    }

    private function absent(Course|CourseModule|Lesson $model, string $label, bool $archiveMissing, ImportReport $report): void
    {
        $report->absent[] = $label;
        if ($archiveMissing && $model->status !== PublicationStatus::Archived) {
            $this->setStatus($model, PublicationStatus::Archived);
            $model->save();
            $report->archived[] = $label;
        }
    }

    private function syncQuiz(Lesson $lesson, ?QuizDraft $draft, ImportReport $report): void
    {
        $active = Quiz::query()->where('lesson_id', $lesson->id)->whereNull('retired_at')->first();
        if ($active !== null && $active->key !== $draft?->key) {
            $this->removeQuiz($active, $report);
        }
        if ($draft === null) {
            return;
        }

        // O preflight garante que um quiz com essa key, se existir, é desta aula.
        $quiz = Quiz::query()->where('key', $draft->key)->first();
        if ($quiz === null) {
            $quiz = new Quiz;
            $quiz->forceFill([
                'lesson_id' => $lesson->id,
                'key' => $draft->key,
                'title' => $draft->title,
                'max_attempts' => $draft->maxAttempts,
                'pass_percent' => $draft->passPercent,
                'revision' => 1,
            ])->save();
            $this->syncQuestions($quiz, $draft, $report);
            $report->count('quizzes', true, true);

            return;
        }

        $gradingChanged = $this->gradingHash($quiz) !== $draft->hash();
        $quiz->forceFill([
            'title' => $draft->title,
            'max_attempts' => $draft->maxAttempts,
            'pass_percent' => $draft->passPercent,
            'retired_at' => null,
        ]);
        if ($gradingChanged) {
            $this->syncQuestions($quiz, $draft, $report);
            $quiz->revision++;
            $report->quizRevisions++;
        }
        $this->persist($quiz, 'quizzes', $report);
    }

    /** Hash da correção do quiz como está no banco (perguntas e opções ativas, em ordem). */
    private function gradingHash(Quiz $quiz): string
    {
        $questions = QuizQuestion::query()->where('quiz_id', $quiz->id)->whereNull('retired_at')->orderBy('position')->get();
        $options = QuizOption::query()->whereIn('question_id', $questions->modelKeys())->whereNull('retired_at')
            ->orderBy('position')->get()->groupBy('question_id');

        return QuizDraft::gradingHash($quiz->max_attempts, $quiz->pass_percent, $questions->map(
            fn (QuizQuestion $question) => new QuestionDraft($question->key, $question->kind, $question->prompt, $question->explanation,
                $question->position, ($options->get($question->id) ?? collect())->map(
                    fn (QuizOption $option) => new OptionDraft($option->key, $option->text, $option->is_correct, $option->position),
                )->values()->all()),
        )->values()->all());
    }

    private function syncQuestions(Quiz $quiz, QuizDraft $draft, ImportReport $report): void
    {
        $hasAttempts = $this->usage->hasAttempts($quiz);
        QuizQuestion::query()->where('quiz_id', $quiz->id)->whereNull('retired_at')->toBase()
            ->update(['position' => DB::raw('position + '.self::POSITION_PARKING)]);
        /** @var Collection<string, QuizQuestion> $existing */
        $existing = QuizQuestion::query()->where('quiz_id', $quiz->id)->get()->keyBy('key');
        $keys = array_map(fn (QuestionDraft $question) => $question->key, $draft->questions);

        foreach (self::without($existing, $keys) as $removed) {
            if ($removed->retired_at === null) {
                $this->retireOrDelete($removed, $hasAttempts, $report, fn () => QuizOption::query()->where('question_id', $removed->id)->delete());
            }
        }

        foreach ($draft->questions as $questionDraft) {
            $question = $existing->get($questionDraft->key) ?? new QuizQuestion;
            $question->forceFill([
                'quiz_id' => $quiz->id,
                'key' => $questionDraft->key,
                'kind' => $questionDraft->kind,
                'prompt' => $questionDraft->prompt,
                'explanation' => $questionDraft->explanation,
                'position' => $questionDraft->position,
                'retired_at' => null,
            ])->save();
            $this->syncOptions($question, $questionDraft, $hasAttempts, $report);
        }
    }

    private function syncOptions(QuizQuestion $question, QuestionDraft $draft, bool $hasAttempts, ImportReport $report): void
    {
        QuizOption::query()->where('question_id', $question->id)->whereNull('retired_at')->toBase()
            ->update(['position' => DB::raw('position + '.self::POSITION_PARKING)]);
        /** @var Collection<string, QuizOption> $existing */
        $existing = QuizOption::query()->where('question_id', $question->id)->get()->keyBy('key');
        $keys = array_map(fn (OptionDraft $option) => $option->key, $draft->options);

        foreach (self::without($existing, $keys) as $removed) {
            if ($removed->retired_at === null) {
                $this->retireOrDelete($removed, $hasAttempts, $report);
            }
        }

        foreach ($draft->options as $optionDraft) {
            $option = $existing->get($optionDraft->key) ?? new QuizOption;
            $option->forceFill([
                'question_id' => $question->id,
                'key' => $optionDraft->key,
                'text' => $optionDraft->text,
                'is_correct' => $optionDraft->correct,
                'position' => $optionDraft->position,
                'retired_at' => null,
            ])->save();
        }
    }

    /** Quiz que saiu da aula (ou trocou de key): aposentado se teve tentativa, senão removido. */
    private function removeQuiz(Quiz $quiz, ImportReport $report): void
    {
        $this->retireOrDelete($quiz, $this->usage->hasAttempts($quiz), $report, function () use ($quiz) {
            $questionIds = QuizQuestion::query()->where('quiz_id', $quiz->id)->pluck('id');
            QuizOption::query()->whereIn('question_id', $questionIds)->delete();
            QuizQuestion::query()->where('quiz_id', $quiz->id)->delete();
        });
    }

    /**
     * Item de quiz removido do arquivo (ADR 0018, item 8): com tentativa, o snapshot ainda
     * depende dele, então só é aposentado; sem tentativa, é apagado com os filhos.
     *
     * @param  (callable(): mixed)|null  $deleteChildren
     */
    private function retireOrDelete(Quiz|QuizQuestion|QuizOption $model, bool $hasAttempts, ImportReport $report, ?callable $deleteChildren = null): void
    {
        if ($hasAttempts) {
            $model->forceFill(['retired_at' => $this->now])->save();
            $report->quizItemsRetired++;

            return;
        }
        if ($deleteChildren !== null) {
            $deleteChildren();
        }
        $model->delete();
        $report->quizItemsDeleted++;
    }
}
