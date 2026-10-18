<?php

use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Models\QuizAttemptAnswer;
use Illuminate\Support\Str;
use Tests\Support\LearningFixtures;

/*
| Quizzes (etapa 2.5, ADR 0018, R14–R20b): início, envio, correção no servidor, limites, snapshot e
| liberação do gabarito. Gabarito = `correct` e `explanation`; nunca antes da revisão liberada.
*/

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost:3000']]);
    [, , $lessons] = LearningFixtures::course();
    $this->lessons = $lessons;
});

/** @return list<string> chaves do gabarito presentes em qualquer nível do JSON */
function quizSecrets(mixed $json): array
{
    return array_values(array_intersect(LearningFixtures::keys($json), ['correct', 'explanation', 'is_correct_option', 'snapshot']));
}

/** @return array{0: array<string, string>, 1: User} quiz na aula "peek" e um jogador */
function quizSetup(int $maxAttempts = 3, int $passPercent = 70): array
{
    [, $ids] = LearningFixtures::quiz(test()->lessons['peek'], $maxAttempts, $passPercent);

    return [$ids, User::factory()->create()];
}

function startedAttempt(User $user): string
{
    return LearningFixtures::startAttempt($user)->assertSuccessful()->json('data.id');
}

describe('routes', function () {
    it('require a session', function () {
        quizSetup();
        $attempt = QuizAttempt::factory()->create();

        LearningFixtures::startAttempt(null)->assertUnauthorized();
        LearningFixtures::submitAttempt(null, $attempt->id, [])->assertUnauthorized();
        LearningFixtures::attempt(null, $attempt->id)->assertUnauthorized();
        LearningFixtures::myAttempts(null)->assertUnauthorized();
    });

    it('limit starts and submissions to 10 per minute per player', function () {
        [, $user] = quizSetup();
        for ($i = 0; $i < 10; $i++) {
            LearningFixtures::startAttempt($user)->assertSuccessful();
        }

        LearningFixtures::startAttempt($user)->assertStatus(429);
    });
});

describe('starting an attempt', function () {
    it('returns the questions in order without answer key or explanations, and snapshots everything', function () {
        [$ids, $user] = quizSetup();

        $response = LearningFixtures::startAttempt($user)->assertCreated()
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.questions.0.id', $ids['tecla'])
            ->assertJsonPath('data.questions.0.kind', 'single_choice')
            ->assertJsonPath('data.questions.0.options', [['id' => $ids['tecla.a'], 'text' => 'A'], ['id' => $ids['tecla.d'], 'text' => 'D']])
            ->assertJsonPath('data.questions.1.kind', 'multiple_choice');
        expect(quizSecrets($response->json()))->toBe([])
            ->and($response->getContent())->not->toContain('EXPLICACAO-SECRETA');

        $attempt = QuizAttempt::query()->sole();
        expect($attempt->snapshot['pass_percent'])->toBe(70)
            ->and($attempt->snapshot['questions'][0]['explanation'])->toBe('EXPLICACAO-SECRETA')
            ->and($attempt->snapshot['questions'][0]['options'][1]['correct'])->toBeTrue();
    });

    it('enrolls automatically and marks the lesson in progress (R10, R14)', function () {
        [, $user] = quizSetup();

        startedAttempt($user);

        expect(Enrollment::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(LessonProgress::query()->sole()->status)->toBe(LessonProgressStatus::InProgress);
        LearningFixtures::progress($user)->assertJsonPath('data.lessons.1.status', 'in_progress')
            ->assertJsonPath('data.lessons.1.quiz', ['attempts_used' => 1, 'best_score_percent' => null, 'passed' => false])
            ->assertJsonPath('data.lessons.0.quiz', null)
            ->assertJsonPath('data.completed_lessons', 0);
    });

    it('returns the open attempt again without consuming another one (R15)', function () {
        [, $user] = quizSetup();
        $first = startedAttempt($user);

        LearningFixtures::startAttempt($user)->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.attempt_number', 1);

        expect(QuizAttempt::query()->count())->toBe(1);
    });

    it('answers 404 for unknown, retired or non-public quizzes', function (Closure $change, string $key) {
        quizSetup();
        $change($this->lessons);

        LearningFixtures::startAttempt(User::factory()->editor()->create(), $key)->assertNotFound();
        expect(QuizAttempt::query()->count())->toBe(0);
    })->with([
        'key desconhecida' => [fn () => null, 'nao-existe'],
        'key malformada' => [fn () => null, 'Quiz_Tecla'],
        'quiz aposentado' => [fn () => Quiz::query()->update(['retired_at' => now()]), 'quiz-tecla'],
        'aula arquivada' => [fn (array $lessons) => LearningFixtures::setStatus($lessons['peek'], PublicationStatus::Archived), 'quiz-tecla'],
        'módulo arquivado' => [fn (array $lessons) => LearningFixtures::setStatus(CourseModule::query()->findOrFail($lessons['peek']->module_id), PublicationStatus::Archived), 'quiz-tecla'],
        'curso arquivado' => [fn (array $lessons) => LearningFixtures::setStatus(Course::query()->findOrFail($lessons['peek']->course_id), PublicationStatus::Archived), 'quiz-tecla'],
    ]);

    it('counts open and submitted attempts against the limit (R16)', function () {
        [$ids, $user] = quizSetup(maxAttempts: 2);
        LearningFixtures::submitAttempt($user, startedAttempt($user), LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();
        LearningFixtures::startAttempt($user)->assertCreated()->assertJsonPath('data.attempt_number', 2);

        // A segunda está aberta: iniciar devolve a aberta, não estoura o limite.
        LearningFixtures::startAttempt($user)->assertOk()->assertJsonPath('data.attempt_number', 2);
        LearningFixtures::submitAttempt($user, QuizAttempt::query()->where('attempt_number', 2)->value('id'), LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();

        LearningFixtures::startAttempt($user)->assertStatus(409)
            ->assertExactJson(['message' => 'Você não tem mais tentativas neste quiz.', 'code' => 'quiz_attempts_exhausted']);
    });
});

describe('submitting', function () {
    it('grades on the server, all or nothing per question, without revealing the answer key before release', function () {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);

        $response = LearningFixtures::submitAttempt($user, $attempt, LearningFixtures::answers($ids, ['tecla' => ['d'], 'quando' => ['peek']]))
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.score_percent', 50)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.correct_count', 1)
            ->assertJsonPath('data.question_count', 2)
            ->assertJsonPath('data.attempts_remaining', 2)
            ->assertJsonPath('data.review_available', false)
            ->assertJsonPath('data.questions.0.is_correct', true)
            ->assertJsonPath('data.questions.1.is_correct', false)
            ->assertJsonPath('data.questions.1.selected_option_ids', [$ids['quando.peek']]);
        expect(quizSecrets($response->json()))->toBe([])
            ->and($response->getContent())->not->toContain('EXPLICACAO-SECRETA');

        $saved = QuizAttempt::query()->sole();
        expect([$saved->correct_count, (float) $saved->score_percent, $saved->passed])->toBe([1, 50.0, false])
            ->and(QuizAttemptAnswer::query()->where('attempt_id', $attempt)->count())->toBe(2)
            ->and(QuizAttemptAnswer::query()->where('question_id', $ids['quando'])->sole()->selected_option_ids)->toBe([$ids['quando.peek']]);
    });

    it('ignores any score or result sent by the client', function () {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);

        LearningFixtures::as($user);
        $this->postJson("/api/v1/academy/quiz-attempts/{$attempt}/submission", [
            'answers' => LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()),
            'score_percent' => 100,
            'passed' => true,
        ])->assertOk()->assertJsonPath('data.score_percent', 0)->assertJsonPath('data.passed', false);
    });

    it('rejects answers that do not match the attempt snapshot (422)', function (Closure $answers, string $field) {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);

        LearningFixtures::submitAttempt($user, $attempt, $answers($ids))->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([$field]);
        expect(QuizAttempt::query()->sole()->status->value)->toBe('in_progress');
    })->with([
        'pergunta faltando' => [fn (array $ids) => LearningFixtures::answers($ids, ['tecla' => ['d']]), 'answers'],
        'pergunta repetida' => [fn (array $ids) => [...LearningFixtures::answers($ids, LearningFixtures::rightAnswers()), ...LearningFixtures::answers($ids, ['tecla' => ['d']])], 'answers.2.question_id'],
        'pergunta de fora' => [fn (array $ids) => [['question_id' => (string) Str::uuid(), 'option_ids' => [$ids['tecla.d']]], ...LearningFixtures::answers($ids, LearningFixtures::rightAnswers())], 'answers.0.question_id'],
        'opção de outra pergunta' => [fn (array $ids) => [['question_id' => $ids['tecla'], 'option_ids' => [$ids['quando.peek']]], ...LearningFixtures::answers($ids, ['quando' => ['peek']])], 'answers.0.option_ids'],
        'duas opções em escolha única' => [fn (array $ids) => LearningFixtures::answers($ids, ['tecla' => ['a', 'd'], 'quando' => ['peek']]), 'answers.0.option_ids'],
        'opção repetida' => [fn (array $ids) => LearningFixtures::answers($ids, ['tecla' => ['d'], 'quando' => ['peek', 'peek']]), 'answers.1.option_ids'],
        'sem opções' => [fn (array $ids) => [['question_id' => $ids['tecla'], 'option_ids' => []], ...LearningFixtures::answers($ids, ['quando' => ['peek']])], 'answers.0.option_ids'],
        'id que não é uuid' => [fn (array $ids) => [['question_id' => 'abc', 'option_ids' => ['x']]], 'answers.0.question_id'],
        'campo extra na resposta' => [fn (array $ids) => [['question_id' => $ids['tecla'], 'option_ids' => [$ids['tecla.d']], 'correct' => true]], 'answers.0'],
    ]);

    it('refuses a second submission (409)', function () {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);
        LearningFixtures::submitAttempt($user, $attempt, LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();

        LearningFixtures::submitAttempt($user, $attempt, LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertStatus(409)
            ->assertJsonPath('code', 'quiz_attempt_already_submitted');
        expect(QuizAttempt::query()->sole()->passed)->toBeFalse();
    });

    it('grades against the snapshot even if the quiz changes during the attempt (ADR 0018, item 7)', function () {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);

        // A importação inverte o gabarito e muda o texto no meio da tentativa.
        QuizOption::query()->whereKey($ids['tecla.d'])->update(['is_correct' => false]);
        QuizOption::query()->whereKey($ids['tecla.a'])->update(['is_correct' => true]);
        QuizQuestion::query()->whereKey($ids['tecla'])->update(['prompt' => 'Texto novo']);

        LearningFixtures::submitAttempt($user, $attempt, LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertOk()
            ->assertJsonPath('data.score_percent', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.questions.0.prompt', 'Qual tecla para mais rápido?')
            ->assertJsonPath('data.questions.0.options.1.correct', true);
    });

    it('lets an open attempt of a retired quiz be submitted while the lesson stays public, but starts none (Q3, item 8)', function () {
        [$ids, $user] = quizSetup();
        $open = startedAttempt($user);

        // A importação trocou a key do quiz: o antigo, com tentativa, é aposentado; a aula segue pública.
        Quiz::query()->update(['retired_at' => now()]);

        LearningFixtures::startAttempt($user)->assertNotFound();
        LearningFixtures::submitAttempt($user, $open, LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertOk()
            ->assertJsonPath('data.passed', true);
    });

    it('never changes the snapshot after the attempt starts (ADR 0018, item 7)', function () {
        [$ids, $user] = quizSetup();
        $attempt = startedAttempt($user);
        $before = QuizAttempt::query()->findOrFail($attempt)->getRawOriginal('snapshot');

        QuizQuestion::query()->whereKey($ids['tecla'])->update(['prompt' => 'Texto novo', 'explanation' => 'Outra']);
        Quiz::query()->update(['pass_percent' => 100, 'revision' => 2]);
        LearningFixtures::startAttempt($user)->assertOk();
        LearningFixtures::submitAttempt($user, $attempt, LearningFixtures::answers($ids, ['tecla' => ['d'], 'quando' => ['peek', 'duelo']]))->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.quiz_revision', 1);
        LearningFixtures::attempt($user, $attempt)->assertOk();

        expect(QuizAttempt::query()->findOrFail($attempt)->getRawOriginal('snapshot'))->toBe($before);
    });

    it('answers 404 to submit an open attempt once the lesson is archived, keeping the history (item 15)', function () {
        [$ids, $user] = quizSetup();
        $first = startedAttempt($user);
        LearningFixtures::submitAttempt($user, $first, LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();
        $open = startedAttempt($user);

        LearningFixtures::setStatus($this->lessons['peek'], PublicationStatus::Archived);

        LearningFixtures::submitAttempt($user, $open, LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertNotFound();
        LearningFixtures::attempt($user, $first)->assertOk()->assertJsonPath('data.status', 'submitted');
        LearningFixtures::myAttempts($user)->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('answer key release (R20–R20b)', function () {
    it('releases the review in the same response that passes, and refuses new attempts after passing (R16a)', function () {
        [$ids, $user] = quizSetup();
        $response = LearningFixtures::submitAttempt($user, startedAttempt($user), LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.review_available', true)
            ->assertJsonPath('data.questions.0.explanation', 'EXPLICACAO-SECRETA')
            ->assertJsonPath('data.questions.0.options', [
                ['id' => $ids['tecla.a'], 'text' => 'A', 'correct' => false],
                ['id' => $ids['tecla.d'], 'text' => 'D', 'correct' => true],
            ]);
        expect($response->json('data.attempts_remaining'))->toBe(2);

        LearningFixtures::startAttempt($user)->assertStatus(409)->assertJsonPath('code', 'quiz_already_passed');
    });

    it('releases the review of every attempt after passing, each from its own snapshot', function () {
        [$ids, $user] = quizSetup();
        $first = startedAttempt($user);
        LearningFixtures::submitAttempt($user, $first, LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();
        LearningFixtures::attempt($user, $first)->assertOk()->assertJsonPath('data.review_available', false)
            ->assertJsonMissingPath('data.questions.0.explanation');

        QuizQuestion::query()->whereKey($ids['tecla'])->update(['explanation' => 'Explicação nova']);
        LearningFixtures::submitAttempt($user, startedAttempt($user), LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertOk();

        LearningFixtures::attempt($user, $first)->assertOk()
            ->assertJsonPath('data.review_available', true)
            ->assertJsonPath('data.questions.0.explanation', 'EXPLICACAO-SECRETA');
    });

    it('releases the review only when every attempt was submitted, not with the last one still open', function () {
        [$ids, $user] = quizSetup(maxAttempts: 2);
        $first = startedAttempt($user);
        LearningFixtures::submitAttempt($user, $first, LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();
        $second = startedAttempt($user);

        LearningFixtures::attempt($user, $first)->assertOk()
            ->assertJsonPath('data.attempts_remaining', 0)
            ->assertJsonPath('data.review_available', false);
        LearningFixtures::attempt($user, $second)->assertOk()->assertJsonPath('data.status', 'in_progress');
        expect(quizSecrets(LearningFixtures::attempt($user, $second)->json()))->toBe([]);

        LearningFixtures::submitAttempt($user, $second, LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk()
            ->assertJsonPath('data.review_available', true)
            ->assertJsonPath('data.questions.0.options.1.correct', true);
        LearningFixtures::attempt($user, $first)->assertJsonPath('data.review_available', true);
    });

    it('keeps an approval after the quiz revision changes (ADR 0018, item 5)', function () {
        [$ids, $user] = quizSetup();
        LearningFixtures::submitAttempt($user, startedAttempt($user), LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertOk();

        Quiz::query()->update(['revision' => 2]);
        QuizOption::query()->whereKey($ids['tecla.a'])->update(['is_correct' => true]);

        LearningFixtures::startAttempt($user)->assertStatus(409)->assertJsonPath('code', 'quiz_already_passed');
        LearningFixtures::progress($user)->assertJsonPath('data.lessons.1.quiz.passed', true)
            ->assertJsonPath('data.lessons.1.quiz.best_score_percent', 100);
        LearningFixtures::myAttempts($user)->assertJsonPath('data.0.quiz_revision', 1)->assertJsonPath('data.0.passed', true);
    });
});

describe('ownership', function () {
    it('never shows or accepts another player attempt (404), whatever the role', function (string $role) {
        [$ids, $owner] = quizSetup();
        $attempt = startedAttempt($owner);
        $intruder = match ($role) {
            'user' => User::factory()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        };

        LearningFixtures::attempt($intruder, $attempt)->assertNotFound()->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
        LearningFixtures::submitAttempt($intruder, $attempt, LearningFixtures::answers($ids, LearningFixtures::rightAnswers()))->assertNotFound();
        LearningFixtures::myAttempts($intruder)->assertOk()->assertExactJson(['data' => []]);

        expect(QuizAttempt::query()->sole()->status->value)->toBe('in_progress');
        expect($intruder->can('view', QuizAttempt::query()->sole()))->toBeFalse();
    })->with(['user', 'editor', 'admin']);

    it('lists the player attempts on a quiz, most recent first, and nothing for unknown quizzes', function () {
        [$ids, $user] = quizSetup();
        LearningFixtures::submitAttempt($user, startedAttempt($user), LearningFixtures::answers($ids, LearningFixtures::wrongAnswers()))->assertOk();
        startedAttempt($user);

        $list = LearningFixtures::myAttempts($user)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attempt_number', 2)
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.0.score_percent', null)
            ->assertJsonPath('data.1.score_percent', 0)
            ->assertJsonPath('data.1.review_available', false);
        expect(quizSecrets($list->json()))->toBe([]);

        LearningFixtures::myAttempts($user, 'nao-existe')->assertOk()->assertExactJson(['data' => []]);
        LearningFixtures::as($user);
        $this->getJson('/api/v1/me/quiz-attempts')->assertUnprocessable();
    });

    it('answers 404 for unknown attempt ids and non-uuid paths', function () {
        [, $user] = quizSetup();

        LearningFixtures::attempt($user, (string) Str::uuid())->assertNotFound();
        LearningFixtures::attempt($user, 'nao-e-uuid')->assertNotFound();
    });
});

it('tells the importer the quiz has attempts, so removed questions are retired (ADR 0018, item 8)', function () {
    [$quiz] = LearningFixtures::quiz($this->lessons['mira'], key: 'quiz-mira');
    $usage = app(QuizUsage::class);
    expect($usage->hasAttempts($quiz))->toBeFalse();

    LearningFixtures::startAttempt(User::factory()->create(), 'quiz-mira')->assertCreated();

    expect($usage->hasAttempts($quiz))->toBeTrue();
});
