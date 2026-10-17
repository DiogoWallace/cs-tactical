<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\LessonContentSchema;
use App\Modules\Academy\Content\MarkdownRules;
use App\Modules\Academy\Content\ValidationErrors;
use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Enums\QuestionKind;
use App\Modules\Academy\Import\Draft\ContentPlan;
use App\Modules\Academy\Import\Draft\CourseDraft;
use App\Modules\Academy\Import\Draft\LessonDraft;
use App\Modules\Academy\Import\Draft\ModuleDraft;
use App\Modules\Academy\Import\Draft\OptionDraft;
use App\Modules\Academy\Import\Draft\QuestionDraft;
use App\Modules\Academy\Import\Draft\QuizDraft;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Fase (a) da importação (ADR 0016): lê a pasta de conteúdo e valida **tudo** sem tocar no banco
 * nem no disco `academy`. Devolve o plano só se não houver nenhum erro.
 *
 * Estrutura em docs/academy/conteudo-importavel.md. Entradas que começam com `_` ou `.` são
 * ignoradas (rascunhos locais); arquivos `.md` também (anotações). Links simbólicos reprovam,
 * para nada ser lido de fora da pasta.
 */
final class ContentParser
{
    private const MAX_FILE_BYTES = 1024 * 1024;

    private const MAX_POSITION = 100000;

    /** @var array<string, string> key do quiz → local onde foi declarado */
    private array $quizKeys = [];

    public function __construct(private readonly ImageInspector $inspector) {}

    public function parse(string $root, ValidationErrors $errors): ?ContentPlan
    {
        $this->quizKeys = [];
        $root = rtrim($root, '/');
        if (! is_dir($root) || is_link($root)) {
            $errors->add($root, 'pasta de conteúdo não encontrada');

            return null;
        }

        $courses = [];
        $images = [];
        foreach ($this->entries($root, '', $errors) as $name => $isDir) {
            if (! $isDir) {
                $errors->add($name, 'arquivo inesperado na raiz (cada curso é uma pasta)');

                continue;
            }
            $media = new CourseMedia("{$root}/{$name}/media", "{$name}/media", $this->inspector);
            $course = $this->course("{$root}/{$name}", $name, $media, $errors);
            if ($course !== null) {
                $courses[] = $course;
            }
            $images += $media->images();
        }

        $this->checkUnique($courses, 'curso', $errors);

        return $errors->isEmpty() ? new ContentPlan($courses, $images) : null;
    }

    private function course(string $directory, string $relative, CourseMedia $media, ValidationErrors $errors): ?CourseDraft
    {
        $location = "{$relative}/course.yaml";
        $data = $this->yaml("{$directory}/course.yaml", $location, $errors);

        $modules = [];
        foreach ($this->entries($directory, $relative, $errors) as $name => $isDir) {
            if ($name === 'course.yaml' || ($name === 'media' && $isDir)) {
                continue;
            }
            if (! $isDir) {
                $errors->add("{$relative}/{$name}", 'arquivo inesperado na pasta do curso (módulos são pastas)');

                continue;
            }
            $module = $this->module("{$directory}/{$name}", "{$relative}/{$name}", $media, $errors);
            if ($module !== null) {
                $modules[] = $module;
            }
        }

        if ($data === null) {
            return null;
        }

        $before = $errors->count();
        Fields::rejectUnknown($data, ['key', 'slug', 'title', 'summary', 'level', 'status', 'position', 'cover_image'], $location, $errors);
        $key = Fields::key($data, 'key', $location, $errors);
        $slug = Fields::key($data, 'slug', $location, $errors);
        $title = Fields::string($data, 'title', $location, $errors, 1, 160);
        $summary = Fields::string($data, 'summary', $location, $errors, 1, 500);
        $level = Fields::choice($data, 'level', CourseLevel::values(), $location, $errors);
        $status = Fields::choice($data, 'status', PublicationStatus::values(), $location, $errors);
        $position = Fields::int($data, 'position', $location, $errors, 0, self::MAX_POSITION);
        $cover = Fields::string($data, 'cover_image', $location, $errors, 1, 120, required: false);
        $coverSha = $cover === null ? null : $media->sha($cover, Fields::at($location, 'cover_image'), $errors);

        $this->checkUnique($modules, 'módulo', $errors);
        $lessons = array_merge(...array_map(fn (ModuleDraft $module) => $module->lessons, $modules));
        $this->checkUnique($lessons, 'aula', $errors, position: false);

        if ($status === PublicationStatus::Published->value && ! $this->hasPublishedLesson($modules)) {
            $errors->add(Fields::at($location, 'status'), 'curso publicado precisa de ao menos um módulo publicado com uma aula publicada');
        }

        if ($errors->count() !== $before || $key === null || $slug === null || $title === null || $summary === null
            || $level === null || $status === null || $position === null || ($cover !== null && $coverSha === null)) {
            return null;
        }

        return new CourseDraft($location, $key, $slug, $title, $summary, CourseLevel::from($level),
            PublicationStatus::from($status), $position, $coverSha, $modules);
    }

    private function module(string $directory, string $relative, CourseMedia $media, ValidationErrors $errors): ?ModuleDraft
    {
        $location = "{$relative}/module.yaml";
        $data = $this->yaml("{$directory}/module.yaml", $location, $errors);

        $lessons = [];
        foreach ($this->entries($directory, $relative, $errors) as $name => $isDir) {
            if ($name === 'module.yaml') {
                continue;
            }
            if ($isDir || ! str_ends_with($name, '.yaml')) {
                $errors->add("{$relative}/{$name}", 'só arquivos .yaml de aula são aceitos na pasta do módulo');

                continue;
            }
            $lesson = $this->lesson("{$directory}/{$name}", "{$relative}/{$name}", $media, $errors);
            if ($lesson !== null) {
                $lessons[] = $lesson;
            }
        }

        if ($data === null) {
            return null;
        }

        $before = $errors->count();
        Fields::rejectUnknown($data, ['key', 'slug', 'title', 'summary', 'status', 'position'], $location, $errors);
        $key = Fields::key($data, 'key', $location, $errors);
        $slug = Fields::key($data, 'slug', $location, $errors);
        $title = Fields::string($data, 'title', $location, $errors, 1, 160);
        $summary = Fields::string($data, 'summary', $location, $errors, 1, 500, required: false);
        $status = Fields::choice($data, 'status', PublicationStatus::values(), $location, $errors);
        $position = Fields::int($data, 'position', $location, $errors, 0, self::MAX_POSITION);

        $this->checkPositions($lessons, 'aula', $errors);

        if ($errors->count() !== $before || $key === null || $slug === null || $title === null || $status === null || $position === null) {
            return null;
        }

        return new ModuleDraft($location, $key, $slug, $title, $summary, PublicationStatus::from($status), $position, $lessons);
    }

    private function lesson(string $file, string $location, CourseMedia $media, ValidationErrors $errors): ?LessonDraft
    {
        $data = $this->yaml($file, $location, $errors);
        if ($data === null) {
            return null;
        }

        $before = $errors->count();
        Fields::rejectUnknown($data, ['key', 'slug', 'title', 'summary', 'status', 'position', 'estimated_minutes', 'content', 'quiz'], $location, $errors);
        $key = Fields::key($data, 'key', $location, $errors);
        $slug = Fields::key($data, 'slug', $location, $errors);
        $title = Fields::string($data, 'title', $location, $errors, 1, 160);
        $summary = Fields::string($data, 'summary', $location, $errors, 1, 500, required: false);
        $status = Fields::choice($data, 'status', PublicationStatus::values(), $location, $errors);
        $position = Fields::int($data, 'position', $location, $errors, 0, self::MAX_POSITION);
        $minutes = Fields::int($data, 'estimated_minutes', $location, $errors, 1, 600, required: false);

        $quiz = null;
        $quizKey = null;
        if (array_key_exists('quiz', $data)) {
            $quizData = $data['quiz'];
            $quizKey = is_array($quizData) && is_string($quizData['key'] ?? null) ? $quizData['key'] : null;
            $quiz = $this->quiz($quizData, Fields::at($location, 'quiz'), $errors);
        }

        $content = LessonContentSchema::normalize($data['content'] ?? null, Fields::at($location, 'content'), new LessonContext($media, $quizKey), $errors);

        if ($errors->count() !== $before || $key === null || $slug === null || $title === null || $status === null
            || $position === null || $content === null || (array_key_exists('quiz', $data) && $quiz === null)) {
            return null;
        }

        return new LessonDraft($location, $key, $slug, $title, $summary, PublicationStatus::from($status), $position,
            $minutes, $content, CanonicalJson::hash($content), $quiz);
    }

    private function quiz(mixed $data, string $location, ValidationErrors $errors): ?QuizDraft
    {
        if (! is_array($data) || array_is_list($data)) {
            $errors->add($location, 'precisa ser um mapa de campos');

            return null;
        }

        $before = $errors->count();
        Fields::rejectUnknown($data, ['key', 'title', 'max_attempts', 'pass_percent', 'questions'], $location, $errors);
        $key = Fields::key($data, 'key', $location, $errors);
        $title = Fields::string($data, 'title', $location, $errors, 1, 160);
        $maxAttempts = Fields::int($data, 'max_attempts', $location, $errors, 1, 20, required: false) ?? 3;
        $passPercent = Fields::int($data, 'pass_percent', $location, $errors, 1, 100, required: false) ?? 70;
        $rawQuestions = Fields::listOfMaps($data, 'questions', $location, $errors, 1, 30) ?? [];

        if ($key !== null) {
            if (isset($this->quizKeys[$key])) {
                $errors->add(Fields::at($location, 'key'), "key repetida (também em {$this->quizKeys[$key]})");
            }
            $this->quizKeys[$key] = $location;
        }

        $questions = [];
        $questionKeys = [];
        foreach ($rawQuestions as $index => $raw) {
            $question = $this->question($raw, $index + 1, Fields::at($location, "questions[{$index}]"), $errors);
            if ($question === null) {
                continue;
            }
            if (isset($questionKeys[$question->key])) {
                $errors->add(Fields::at($location, "questions[{$index}].key"), 'key repetida no quiz');
            }
            $questionKeys[$question->key] = true;
            $questions[] = $question;
        }

        if ($errors->count() !== $before || $key === null || $title === null) {
            return null;
        }

        return new QuizDraft($location, $key, $title, $maxAttempts, $passPercent, $questions);
    }

    /** @param  array<array-key, mixed>  $data */
    private function question(array $data, int $position, string $location, ValidationErrors $errors): ?QuestionDraft
    {
        $before = $errors->count();
        Fields::rejectUnknown($data, ['key', 'kind', 'prompt', 'explanation', 'options'], $location, $errors);
        $key = Fields::key($data, 'key', $location, $errors);
        $kind = Fields::choice($data, 'kind', QuestionKind::values(), $location, $errors);
        $prompt = Fields::string($data, 'prompt', $location, $errors, 1, 1000);
        $explanation = Fields::string($data, 'explanation', $location, $errors, 1, 2000, required: false);
        $rawOptions = Fields::listOfMaps($data, 'options', $location, $errors, 2, 8) ?? [];
        if ($prompt !== null) {
            MarkdownRules::inline($prompt, Fields::at($location, 'prompt'), $errors);
        }
        if ($explanation !== null) {
            MarkdownRules::inline($explanation, Fields::at($location, 'explanation'), $errors);
        }

        $options = [];
        $optionKeys = [];
        foreach ($rawOptions as $index => $raw) {
            $at = Fields::at($location, "options[{$index}]");
            Fields::rejectUnknown($raw, ['key', 'text', 'correct'], $at, $errors);
            $optionKey = Fields::key($raw, 'key', $at, $errors);
            $text = Fields::string($raw, 'text', $at, $errors, 1, 500);
            $correct = Fields::bool($raw, 'correct', $at, $errors);
            if ($text !== null) {
                MarkdownRules::inline($text, Fields::at($at, 'text'), $errors);
            }
            if ($optionKey !== null && isset($optionKeys[$optionKey])) {
                $errors->add(Fields::at($at, 'key'), 'key repetida na pergunta');
            }
            if ($optionKey !== null && $text !== null && $correct !== null) {
                $optionKeys[$optionKey] = true;
                $options[] = new OptionDraft($optionKey, $text, $correct, $index + 1);
            }
        }

        if ($errors->count() !== $before || $key === null || $kind === null || $prompt === null) {
            return null;
        }

        $correctCount = count(array_filter($options, fn (OptionDraft $option) => $option->correct));
        if ($kind === QuestionKind::SingleChoice->value && $correctCount !== 1) {
            $errors->add(Fields::at($location, 'options'), 'single_choice precisa de exatamente 1 opção correta');

            return null;
        }
        if ($kind === QuestionKind::MultipleChoice->value && $correctCount < 1) {
            $errors->add(Fields::at($location, 'options'), 'multiple_choice precisa de ao menos 1 opção correta');

            return null;
        }

        return new QuestionDraft($key, QuestionKind::from($kind), $prompt, $explanation, $position, $options);
    }

    /** @param  list<ModuleDraft>  $modules */
    private function hasPublishedLesson(array $modules): bool
    {
        foreach ($modules as $module) {
            if ($module->status !== PublicationStatus::Published) {
                continue;
            }
            foreach ($module->lessons as $lesson) {
                if ($lesson->status === PublicationStatus::Published) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * key, slug (e posição) únicos entre irmãos.
     *
     * @param  list<CourseDraft|ModuleDraft|LessonDraft>  $items
     */
    private function checkUnique(array $items, string $label, ValidationErrors $errors, bool $position = true): void
    {
        foreach (['key', 'slug'] as $field) {
            $seen = [];
            foreach ($items as $item) {
                $value = $item->{$field};
                if (isset($seen[$value])) {
                    $errors->add(Fields::at($item->location, $field), "{$field} de {$label} repetido (também em {$seen[$value]})");
                }
                $seen[$value] = $item->location;
            }
        }
        if ($position) {
            $this->checkPositions($items, $label, $errors);
        }
    }

    /** @param  list<CourseDraft|ModuleDraft|LessonDraft>  $items */
    private function checkPositions(array $items, string $label, ValidationErrors $errors): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->position])) {
                $errors->add(Fields::at($item->location, 'position'), "posição de {$label} repetida (também em {$seen[$item->position]})");
            }
            $seen[$item->position] = $item->location;
        }
    }

    /**
     * Entradas de uma pasta, em ordem alfabética: nome → é pasta?
     *
     * @return array<string, bool>
     */
    private function entries(string $directory, string $relative, ValidationErrors $errors): array
    {
        $names = is_dir($directory) ? scandir($directory) : false;
        if ($names === false) {
            return [];
        }

        $entries = [];
        foreach ($names as $name) {
            if ($name[0] === '.' || $name[0] === '_' || str_ends_with($name, '.md')) {
                continue;
            }
            $path = "{$directory}/{$name}";
            $location = ltrim("{$relative}/{$name}", '/');
            if (is_link($path)) {
                $errors->add($location, 'links simbólicos não são aceitos');

                continue;
            }
            $entries[$name] = is_dir($path);
        }

        return $entries;
    }

    /** @return array<array-key, mixed>|null */
    private function yaml(string $file, string $location, ValidationErrors $errors): ?array
    {
        if (! is_file($file)) {
            $errors->add($location, 'arquivo obrigatório não encontrado');

            return null;
        }
        if ((int) filesize($file) > self::MAX_FILE_BYTES) {
            $errors->add($location, 'arquivo maior que 1 MB');

            return null;
        }

        try {
            // Tags personalizadas, constantes e objetos PHP reprovam; chave repetida também.
            $data = Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            $errors->add($location, 'YAML inválido: '.$exception->getMessage());

            return null;
        }

        if (! is_array($data) || array_is_list($data)) {
            $errors->add($location, 'precisa ser um mapa de campos');

            return null;
        }

        return $data;
    }
}
