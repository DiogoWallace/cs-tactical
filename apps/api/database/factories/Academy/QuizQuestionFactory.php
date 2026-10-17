<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Enums\QuestionKind;
use App\Modules\Academy\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pergunta de escolha única; sem `for(...)`, cria também o quiz.
 *
 * @extends Factory<QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    protected $model = QuizQuestion::class;

    private static int $position = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quiz_id' => QuizFactory::new(),
            'key' => 'pergunta-'.fake()->unique()->numerify('######'),
            'kind' => QuestionKind::SingleChoice,
            'prompt' => fake()->sentence(8).'?',
            'position' => self::$position++,
        ];
    }
}
