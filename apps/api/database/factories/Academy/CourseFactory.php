<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Curso em rascunho. `published()` e `archived()` preenchem as datas exigidas pelas constraints.
 *
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    private static int $position = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'curso-'.fake()->unique()->numerify('######');

        return [
            'key' => $key,
            'slug' => $key,
            'title' => fake()->sentence(3),
            'summary' => fake()->sentence(10),
            'level' => CourseLevel::Beginner,
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
