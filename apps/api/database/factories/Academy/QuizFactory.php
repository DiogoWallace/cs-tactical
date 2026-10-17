<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Quiz ativo; sem `for(...)`, cria também a aula.
 *
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lesson_id' => LessonFactory::new(),
            'key' => 'quiz-'.fake()->unique()->numerify('######'),
            'title' => fake()->sentence(3),
        ];
    }
}
