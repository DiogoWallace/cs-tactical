<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Models\QuizOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Opção incorreta; sem `for(...)`, cria também a pergunta.
 *
 * @extends Factory<QuizOption>
 */
class QuizOptionFactory extends Factory
{
    protected $model = QuizOption::class;

    private static int $position = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'question_id' => QuizQuestionFactory::new(),
            'key' => 'opcao-'.fake()->unique()->numerify('######'),
            'text' => fake()->words(3, true),
            'is_correct' => false,
            'position' => self::$position++,
        ];
    }
}
