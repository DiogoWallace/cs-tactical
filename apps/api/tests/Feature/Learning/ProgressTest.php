<?php

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Tests\Support\LearningFixtures;

/*
| Leituras do Learning: GET .../progress e GET /api/v1/me/enrollments (R10, R11, R13, R13a).
*/

beforeEach(fn () => config(['sanctum.stateful' => ['localhost:3000']]));

describe('GET /api/v1/academy/courses/{course}/progress', function () {
    it('requires a session', function () {
        LearningFixtures::course();

        LearningFixtures::progress(null)->assertUnauthorized();
    });

    it('answers enrolled false with an empty list when the player is not enrolled', function () {
        LearningFixtures::course();

        LearningFixtures::progress(User::factory()->create())->assertOk()->assertExactJson(['data' => [
            'enrolled' => false,
            'enrolled_at' => null,
            'completed_at' => null,
            'percent' => null,
            'completed_lessons' => null,
            'total_lessons' => null,
            'new_lessons_available' => false,
            'next_lesson' => null,
            'lessons' => [],
        ]]);
    });

    it('computes the percentage over the public lessons and points to the first one not completed', function () {
        [, $modules] = LearningFixtures::course();
        LearningFixtures::lesson($modules['mira'], 'spray', 2);
        LearningFixtures::lesson($modules['mira'], 'rascunho', 3, PublicationStatus::Draft);
        $user = User::factory()->create();
        foreach (['counter-strafe', 'peek', 'spray'] as $lesson) {
            LearningFixtures::complete($user, $lesson);
        }

        // 3 de 4 aulas públicas (o rascunho não conta); a próxima é a primeira não concluída na ordem.
        $response = LearningFixtures::progress($user)->assertOk()
            ->assertJsonPath('data.percent', 75)
            ->assertJsonPath('data.completed_lessons', 3)
            ->assertJsonPath('data.total_lessons', 4)
            ->assertJsonPath('data.next_lesson', ['slug' => 'mira', 'title' => 'Mira']);
        expect(array_column($response->json('data.lessons'), 'slug'))->toBe(['counter-strafe', 'peek', 'mira', 'spray'])
            ->and(array_column($response->json('data.lessons'), 'status'))->toBe(['completed', 'completed', 'not_started', 'completed']);
        LearningFixtures::assertNoInternalFields($response);
    });

    it('shows only the session player progress', function () {
        LearningFixtures::course();
        [$ana, $bia] = User::factory()->count(2)->create();
        LearningFixtures::complete($ana, 'peek');

        LearningFixtures::progress($bia)->assertOk()->assertJsonPath('data.enrolled', false);
        LearningFixtures::complete($bia, 'mira')->assertJsonPath('data.completed_lessons', 1)
            ->assertJsonPath('data.lessons.1.status', 'not_started');
    });

    it('answers 404 for courses that are not public, even to enrolled players and editors', function (PublicationStatus $status) {
        [$course] = LearningFixtures::course();
        $editor = User::factory()->editor()->create();
        LearningFixtures::enroll($editor)->assertCreated();
        LearningFixtures::setStatus($course, $status);

        LearningFixtures::as($editor);
        $this->getJson('/api/v1/academy/courses/fundamentos/progress?include_unpublished=true')->assertNotFound();
    })->with(['arquivado' => [PublicationStatus::Archived]]);

    it('uses a fixed number of queries, whatever the number of lessons', function (int $extraLessons) {
        [, $modules] = LearningFixtures::course();
        for ($i = 1; $i <= $extraLessons; $i++) {
            LearningFixtures::lesson($modules['mira'], "extra-{$i}", $i + 1);
        }
        $user = User::factory()->create();
        LearningFixtures::complete($user, 'peek');
        LearningFixtures::as($user);

        $sql = LearningFixtures::sql(fn () => $this->getJson('/api/v1/academy/courses/fundamentos/progress')->assertOk());

        // curso público, matrícula, aulas públicas e conclusões.
        expect($sql['queries'])->toHaveCount(4);
    })->with([0, 12]);
});

describe('GET /api/v1/me/enrollments', function () {
    it('requires a session', function () {
        LearningFixtures::enrollments(null)->assertUnauthorized();
    });

    it('lists the player enrollments, most recent activity first, with progress and next lesson', function () {
        LearningFixtures::course();
        LearningFixtures::course('avancado');
        $user = User::factory()->create();
        LearningFixtures::complete($user, 'peek');
        $this->travel(1)->hour();
        LearningFixtures::enroll($user, 'avancado');

        $response = LearningFixtures::enrollments($user)->assertOk()->assertJsonCount(2, 'data');
        expect($response->json('data.0.course'))->toBe(['slug' => 'avancado', 'title' => Course::query()->where('slug', 'avancado')->value('title'), 'status' => 'published'])
            ->and($response->json('data.0.progress'))->toBe(['completed_lessons' => 0, 'total_lessons' => 3, 'percent' => 0])
            ->and($response->json('data.0.next_lesson.slug'))->toBe('counter-strafe')
            ->and($response->json('data.1.course.slug'))->toBe('fundamentos')
            ->and($response->json('data.1.progress'))->toBe(['completed_lessons' => 1, 'total_lessons' => 3, 'percent' => 33.33])
            ->and($response->json('data.1.next_lesson'))->toBe(['slug' => 'counter-strafe', 'title' => 'Counter strafe']);
        LearningFixtures::assertNoInternalFields($response);
    });

    it('keeps the history of archived courses, without percentage or next lesson (R13a)', function () {
        [$course] = LearningFixtures::course();
        $user = User::factory()->create();
        LearningFixtures::complete($user, 'peek');
        LearningFixtures::complete($user, 'mira');
        LearningFixtures::setStatus($course, PublicationStatus::Archived);

        LearningFixtures::enrollments($user)->assertOk()
            ->assertJsonPath('data.0.course.status', 'archived')
            ->assertJsonPath('data.0.course.title', $course->title)
            ->assertJsonPath('data.0.progress', ['completed_lessons' => 2, 'total_lessons' => null, 'percent' => null])
            ->assertJsonPath('data.0.next_lesson', null);
    });

    it('never shows enrollments of other players, whatever their role', function (string $role) {
        LearningFixtures::course();
        $owner = User::factory()->create();
        LearningFixtures::complete($owner, 'peek');
        $viewer = match ($role) {
            'user' => User::factory()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        };

        LearningFixtures::enrollments($viewer)->assertOk()->assertExactJson(['data' => []]);
    })->with(['user', 'editor', 'admin']);

    it('uses a fixed number of queries, whatever the number of enrollments', function (int $courses) {
        $user = User::factory()->create();
        for ($i = 1; $i <= $courses; $i++) {
            LearningFixtures::course("curso-{$i}");
            LearningFixtures::complete($user, 'peek', "curso-{$i}");
        }
        LearningFixtures::as($user);

        $sql = LearningFixtures::sql(fn () => $this->getJson('/api/v1/me/enrollments')->assertOk()->assertJsonCount($courses, 'data'));

        // matrículas, cursos, aulas públicas e conclusões.
        expect($sql['queries'])->toHaveCount(4);
    })->with([1, 5]);
});

describe('authorization matrix', function () {
    it('lets every signed-in role use its own Learning data and nobody else\'s', function (string $role) {
        LearningFixtures::course();
        $user = match ($role) {
            'user' => User::factory()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        };
        $other = User::factory()->create();
        LearningFixtures::complete($other, 'mira');

        LearningFixtures::enroll($user)->assertCreated();
        LearningFixtures::complete($user, 'peek')->assertOk()->assertJsonPath('data.completed_lessons', 1);
        LearningFixtures::progress($user)->assertOk()->assertJsonPath('data.lessons.2.status', 'not_started');
        LearningFixtures::enrollments($user)->assertOk()->assertJsonCount(1, 'data');

        expect(LessonProgress::query()->where('user_id', $other->id)->count())->toBe(1);
    })->with(['user', 'editor', 'admin']);

    it('denies other players records in the policies, even to admins', function () {
        $enrollment = Enrollment::factory()->create();
        $progress = LessonProgress::factory()->create(['user_id' => $enrollment->user_id]);
        $admin = User::factory()->admin()->create();

        expect($admin->can('view', $enrollment))->toBeFalse()
            ->and($admin->can('view', $progress))->toBeFalse()
            ->and($enrollment->user->can('view', $enrollment))->toBeTrue()
            ->and($enrollment->user->can('view', $progress))->toBeTrue();
    });
});
