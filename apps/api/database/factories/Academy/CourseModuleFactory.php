<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\CourseModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Módulo em rascunho; sem `for(...)`, cria também o curso.
 *
 * @extends Factory<CourseModule>
 */
class CourseModuleFactory extends Factory
{
    protected $model = CourseModule::class;

    private static int $position = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'modulo-'.fake()->unique()->numerify('######');

        return [
            'course_id' => CourseFactory::new(),
            'key' => $key,
            'slug' => $key,
            'title' => fake()->sentence(3),
            'status' => PublicationStatus::Draft,
            'position' => self::$position++,
        ];
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
