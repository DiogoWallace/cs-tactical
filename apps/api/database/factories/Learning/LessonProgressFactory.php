<?php

namespace Database\Factories\Learning;

use App\Modules\Academy\Models\Lesson;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Models\LessonProgress;
use Database\Factories\Academy\LessonFactory;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Conclusão de uma aula, com o hash atual do conteúdo dela. O `course_id` vem da aula.
 *
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'lesson_id' => LessonFactory::new(),
            'course_id' => fn (array $attributes) => Lesson::query()->whereKey($attributes['lesson_id'])->value('course_id'),
            'status' => LessonProgressStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
            'completed_content_hash' => fn (array $attributes) => Lesson::query()->whereKey($attributes['lesson_id'])->value('content_hash'),
        ];
    }
}
