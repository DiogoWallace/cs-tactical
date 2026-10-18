<?php

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrontendSession;
use Tests\Support\LearningFixtures;

/*
| PUT /api/v1/academy/courses/{course}/enrollment (R7, docs/api/academy-v1.md).
*/

beforeEach(fn () => config(['sanctum.stateful' => ['localhost:3000']]));

it('requires a session', function () {
    LearningFixtures::course();

    LearningFixtures::enroll(null)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    expect(Enrollment::query()->count())->toBe(0);
});

it('enrolls the session player: 201 the first time, 200 afterwards, without writing again', function () {
    [$course] = LearningFixtures::course();
    $user = User::factory()->create();

    $first = LearningFixtures::enroll($user)->assertCreated()
        ->assertExactJson(['data' => [
            'course' => 'fundamentos',
            'enrolled_at' => Enrollment::query()->sole()->enrolled_at->toIso8601ZuluString(),
            'completed_at' => null,
        ]]);
    LearningFixtures::assertNoInternalFields($first);

    $sql = LearningFixtures::sql(fn () => LearningFixtures::enroll($user)->assertOk()
        ->assertJsonPath('data.enrolled_at', $first->json('data.enrolled_at')));

    $enrollment = Enrollment::query()->sole();
    expect($sql['writes'])->toBe([])
        ->and([$enrollment->user_id, $enrollment->course_id])->toBe([$user->id, $course->id])
        ->and($enrollment->last_activity_at->equalTo($enrollment->enrolled_at))->toBeTrue();
});

it('ignores any user_id sent by the client', function () {
    LearningFixtures::course();
    [$player, $victim] = User::factory()->count(2)->create();

    LearningFixtures::as($player);
    $this->putJson('/api/v1/academy/courses/fundamentos/enrollment', ['user_id' => $victim->id])->assertCreated();

    expect(Enrollment::query()->pluck('user_id')->all())->toBe([$player->id]);
});

it('answers 404 for courses that are not public, for everyone', function (PublicationStatus $status, string $role) {
    $course = Course::factory()->status($status)->create(['slug' => 'fechado']);
    $user = match ($role) {
        'user' => User::factory()->create(),
        'editor' => User::factory()->editor()->create(),
        'admin' => User::factory()->admin()->create(),
    };

    LearningFixtures::as($user);
    $this->putJson('/api/v1/academy/courses/fechado/enrollment?include_unpublished=true')
        ->assertNotFound()->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
    expect(Enrollment::query()->where('course_id', $course->id)->exists())->toBeFalse();
})->with([
    'rascunho' => [PublicationStatus::Draft],
    'arquivado' => [PublicationStatus::Archived],
])->with(['user', 'editor', 'admin']);

it('answers 404 for unknown courses and malformed slugs', function (string $slug) {
    LearningFixtures::course();

    LearningFixtures::enroll(User::factory()->create(), $slug)->assertNotFound();
    expect(Enrollment::query()->count())->toBe(0);
})->with(['nao-existe', 'Fundamentos', 'fundamentos--x', '..%2Fme']);

it('enrolls even when the course has no public lesson yet, without changing the catalog', function () {
    [$course, $modules] = LearningFixtures::course();
    foreach ($modules as $module) {
        LearningFixtures::setStatus($module, PublicationStatus::Archived);
    }
    $catalogBefore = DB::table('courses')->get()->toArray();

    LearningFixtures::enroll(User::factory()->create())->assertCreated();

    expect(DB::table('courses')->get()->toArray())->toEqual($catalogBefore)
        ->and(Enrollment::query()->where('course_id', $course->id)->count())->toBe(1);
});

it('checks the CSRF token of the frontend session', function () {
    LearningFixtures::course();
    $user = User::factory()->create(['password' => 'secret-password-1']);
    $session = (new FrontendSession($this))->realCsrf();
    $session->csrfCookie();
    $session->post('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-password-1'])->assertOk();

    $session->withoutXsrfHeader()->put('/api/v1/academy/courses/fundamentos/enrollment')->assertStatus(419);
    expect(Enrollment::query()->count())->toBe(0);

    $session->withXsrfHeader()->put('/api/v1/academy/courses/fundamentos/enrollment')->assertCreated();
    expect(Enrollment::query()->count())->toBe(1);
});

it('limits writes to 30 per minute per player', function () {
    LearningFixtures::course();
    $user = User::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        LearningFixtures::enroll($user)->assertSuccessful();
    }
    LearningFixtures::enroll($user)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    // Outro jogador tem o próprio limite.
    LearningFixtures::enroll(User::factory()->create())->assertCreated();
});
