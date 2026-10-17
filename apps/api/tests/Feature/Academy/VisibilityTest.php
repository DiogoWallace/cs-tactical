<?php

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;

/*
| Visibilidade efetiva (ADR 0015, regra R2): uma aula é pública só se ela, o módulo e o curso
| estão publicados. Matriz completa: 3 estados × 3 níveis.
*/

$states = [PublicationStatus::Draft, PublicationStatus::Published, PublicationStatus::Archived];
$matrix = [];
foreach ($states as $course) {
    foreach ($states as $module) {
        foreach ($states as $lesson) {
            $matrix["curso {$course->value}, módulo {$module->value}, aula {$lesson->value}"] = [$course, $module, $lesson];
        }
    }
}

it('shows a lesson only when the lesson and all its ancestors are published', function (PublicationStatus $courseStatus, PublicationStatus $moduleStatus, PublicationStatus $lessonStatus) {
    $course = Course::factory()->status($courseStatus)->create();
    $module = CourseModule::factory()->for($course)->status($moduleStatus)->create();
    $lesson = Lesson::factory()->forModule($module)->status($lessonStatus)->create();

    $published = PublicationStatus::Published;
    $expected = $courseStatus === $published && $moduleStatus === $published && $lessonStatus === $published;

    expect(Lesson::query()->visible()->whereKey($lesson->id)->exists())->toBe($expected)
        ->and(CourseModule::query()->visible()->whereKey($module->id)->exists())
        ->toBe($courseStatus === $published && $moduleStatus === $published)
        ->and(Course::query()->visible()->whereKey($course->id)->exists())
        ->toBe($courseStatus === $published);
})->with($matrix);
