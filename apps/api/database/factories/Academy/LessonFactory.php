<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Aula em rascunho com um bloco Markdown. O curso vem do módulo (FK composta): use
 * `forModule($module)` ou deixe criar um módulo novo.
 *
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    private static int $position = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'aula-'.fake()->unique()->numerify('######');
        $content = ['version' => 1, 'blocks' => [['type' => 'markdown', 'data' => ['text' => '## '.fake()->sentence(4)]]]];

        return [
            'module_id' => CourseModuleFactory::new(),
            'course_id' => fn (array $attributes) => CourseModule::query()->whereKey($attributes['module_id'])->value('course_id'),
            'key' => $key,
            'slug' => $key,
            'title' => fake()->sentence(3),
            'position' => self::$position++,
            'content' => $content,
            'content_version' => 1,
            'content_hash' => CanonicalJson::hash($content),
            'status' => PublicationStatus::Draft,
        ];
    }

    public function forModule(CourseModule $module): static
    {
        return $this->state(['module_id' => $module->id, 'course_id' => $module->course_id]);
    }

    public function published(): static
    {
        return $this->state(['status' => PublicationStatus::Published, 'published_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(['status' => PublicationStatus::Archived, 'published_at' => now(), 'archived_at' => now()]);
    }

    public function status(PublicationStatus $status): static
    {
        return match ($status) {
            PublicationStatus::Draft => $this,
            PublicationStatus::Published => $this->published(),
            PublicationStatus::Archived => $this->archived(),
        };
    }
}
