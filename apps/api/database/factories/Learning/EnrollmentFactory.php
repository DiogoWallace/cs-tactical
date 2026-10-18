<?php

namespace Database\Factories\Learning;

use App\Modules\Learning\Models\Enrollment;
use Database\Factories\Academy\CourseFactory;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Matrícula sem conclusão, num curso novo (rascunho, como o CourseFactory). Para um curso
 * público, passe `course_id` de um curso publicado.
 *
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'course_id' => CourseFactory::new(),
            'enrolled_at' => now(),
            'last_activity_at' => now(),
        ];
    }
}
