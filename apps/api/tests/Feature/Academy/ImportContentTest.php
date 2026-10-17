<?php

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use App\Modules\Academy\Support\ContentLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AcademyContent;

/*
| academy:import (ADR 0016, ADR 0019; docs/academy/conteudo-importavel.md).
|
| PostgreSQL real (cs_tactical_test) e armazenamento descartável: Storage::fake('academy') e
| pastas de conteúdo temporárias, apagadas depois de cada teste.
*/

beforeEach(fn () => Storage::fake('academy'));
afterEach(fn () => AcademyContent::cleanup());

/**
 * @param  array<string, mixed>  $tree
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runImport(array $tree, array $options = []): array
{
    $exitCode = Artisan::call('academy:import', ['path' => AcademyContent::write($tree), '--no-interaction' => true, ...$options]);

    return [$exitCode, Artisan::output()];
}

/** @return list<string> SQL de escrita executado durante `$callback` */
function writesDuring(Closure $callback): array
{
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $callback();

    return $writes;
}

function academyRowCounts(): array
{
    return collect(['academy_media', 'courses', 'course_modules', 'lessons', 'quizzes', 'quiz_questions', 'quiz_options'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

function lessonByKey(string $key): Lesson
{
    return Lesson::query()->where('key', $key)->firstOrFail();
}

it('imports courses, modules, lessons, quizzes and images', function () {
    [$exitCode, $output] = runImport(AcademyContent::tree());

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('cursos   criados 1')->toContain('aulas    criadas 3')->toContain('Importação concluída.')
        ->and(academyRowCounts())->toBe([
            'academy_media' => 2, 'courses' => 1, 'course_modules' => 2, 'lessons' => 3,
            'quizzes' => 1, 'quiz_questions' => 2, 'quiz_options' => 5,
        ]);

    $course = Course::query()->sole();
    $cover = Media::query()->where('sha256', hash('sha256', AcademyContent::png(8, 4, 1)))->sole();
    expect($course->status)->toBe(PublicationStatus::Published)
        ->and($course->published_at)->not->toBeNull()
        ->and($course->cover_media_id)->toBe($cover->id)
        ->and([$cover->mime, $cover->width, $cover->height])->toBe(['image/png', 8, 4])
        ->and(Storage::disk('academy')->get($cover->path))->toBe(AcademyContent::png(8, 4, 1));

    $lesson = lessonByKey('counter-strafe');
    $chart = Media::query()->where('sha256', hash('sha256', AcademyContent::png(6, 6, 2)))->sole();
    expect($lesson->content_hash)->toBe(CanonicalJson::hash($lesson->content))
        ->and($lesson->content['blocks'][1])->toEqual(['type' => 'image', 'data' => ['alt' => 'Velocidade caindo a zero', 'caption' => null, 'decorative' => false, 'media' => $chart->sha256]])
        ->and($lesson->content['blocks'][2]['data'])->toMatchArray(['provider' => 'youtube', 'video_id' => 'AbCdEfGhIjK'])
        ->and(Lesson::query()->visible()->pluck('key')->sort()->values()->all())->toBe(['counter-strafe', 'peek']);

    $quiz = $lesson->activeQuiz()->sole();
    expect([$quiz->key, $quiz->max_attempts, $quiz->pass_percent, $quiz->revision])->toBe(['counter-strafe-quiz', 3, 70, 1])
        ->and(QuizOption::query()->where('is_correct', true)->count())->toBe(3);
});

it('validates and imports the example content shipped in content/academy', function () {
    $this->artisan('academy:import', ['--dry-run' => true])
        ->expectsOutputToContain('aulas    criadas 3')
        ->expectsOutputToContain('Simulação: nada foi gravado.')
        ->assertSuccessful();

    $this->artisan('academy:import')->assertSuccessful();
    expect(Course::query()->where('key', 'fundamentos-cs2')->exists())->toBeTrue();
});

it('is idempotent: importing the same files again writes nothing', function () {
    $path = AcademyContent::write(AcademyContent::tree());
    $this->artisan('academy:import', ['path' => $path])->assertSuccessful();
    $before = DB::table('lessons')->orderBy('key')->get(['id', 'updated_at', 'content_hash'])->all();
    $files = Storage::disk('academy')->allFiles();

    $writes = writesDuring(fn () => $this->artisan('academy:import', ['path' => $path])
        ->expectsOutputToContain('cursos   criados 0 · alterados 0 · sem mudança 1')
        ->expectsOutputToContain('aulas    criadas 0 · alteradas 0 · sem mudança 3')
        ->expectsOutputToContain('quizzes  criados 0 · alterados 0 · sem mudança 1')
        ->expectsOutputToContain('arquivos gravados 0')
        ->expectsOutputToContain('Importação concluída: nada mudou.')
        ->assertSuccessful()
        ->run());

    expect($writes)->toBe([])
        ->and(DB::table('lessons')->orderBy('key')->get(['id', 'updated_at', 'content_hash'])->all())->toEqual($before)
        ->and(Storage::disk('academy')->allFiles())->toBe($files);
});

it('keeps the same record when a slug changes and the key stays', function () {
    runImport(AcademyContent::tree());
    $course = Course::query()->sole();
    $lesson = lessonByKey('peek');

    [$exitCode, $output] = runImport(AcademyContent::treeWith([
        'fundamentos.course\.yaml.slug' => 'fundamentos-do-cs2',
        'fundamentos.01-movimentacao.02-peek\.yaml.slug' => 'peek-seguro',
    ]));

    expect($exitCode)->toBe(0)->and($output)->toContain('cursos   criados 0 · alterados 1')
        ->and(Course::query()->sole()->only(['id', 'slug']))->toBe(['id' => $course->id, 'slug' => 'fundamentos-do-cs2'])
        ->and(lessonByKey('peek')->only(['id', 'slug']))->toBe(['id' => $lesson->id, 'slug' => 'peek-seguro'])
        ->and(Lesson::query()->count())->toBe(3);
});

it('swaps slugs and positions inside one import, moves lessons between modules and keeps their ids', function () {
    runImport(AcademyContent::tree());
    $ids = Lesson::query()->pluck('id', 'key');

    [$exitCode, $output] = runImport(AcademyContent::treeWith([
        'fundamentos.01-movimentacao.01-counter-strafe\.yaml.slug' => 'peek',
        'fundamentos.01-movimentacao.01-counter-strafe\.yaml.position' => 2,
        'fundamentos.01-movimentacao.02-peek\.yaml' => null,
        'fundamentos.02-mira.02-peek\.yaml' => [...AcademyContent::tree()['fundamentos']['01-movimentacao']['02-peek.yaml'], 'slug' => 'counter-strafe', 'position' => 2],
        'fundamentos.01-movimentacao.module\.yaml.position' => 2,
        'fundamentos.02-mira.module\.yaml.position' => 1,
        'fundamentos.02-mira.module\.yaml.status' => 'published',
    ]));

    $mira = CourseModule::query()->where('key', 'mira')->sole();
    expect($exitCode)->toBe(0, $output)
        ->and(Lesson::query()->pluck('id', 'key'))->toEqual($ids)
        ->and(lessonByKey('peek')->only(['slug', 'module_id', 'position']))->toBe(['slug' => 'counter-strafe', 'module_id' => $mira->id, 'position' => 2])
        ->and(lessonByKey('counter-strafe')->only(['slug', 'position']))->toBe(['slug' => 'peek', 'position' => 2])
        ->and($mira->position)->toBe(1);
});

it('rejects invalid content with file and field paths, writing nothing', function (array $changes, string $message) {
    [$exitCode, $output] = runImport(AcademyContent::treeWith($changes));

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($message)->toContain('nada foi gravado')
        ->and(array_sum(academyRowCounts()))->toBe(0)
        ->and(Storage::disk('academy')->allFiles())->toBe([]);
})->with([
    'imagem inexistente' => [['fundamentos.01-movimentacao.01-counter-strafe\.yaml.content.blocks.1.src' => 'sumiu.png'],
        'fundamentos/01-movimentacao/01-counter-strafe.yaml: content.blocks[1].src: imagem fundamentos/media/sumiu.png não encontrada'],
    'capa inexistente' => [['fundamentos.media.capa\.png' => null], 'fundamentos/course.yaml: cover_image: imagem fundamentos/media/capa.png não encontrada'],
    'imagem que não é imagem' => [['fundamentos.media.grafico\.png' => '<svg xmlns="http://www.w3.org/2000/svg"/>'], 'não é PNG, JPEG, WebP ou AVIF'],
    'HTML no markdown' => [['fundamentos.01-movimentacao.01-counter-strafe\.yaml.content.blocks.0.text' => 'oi <script>x</script>'],
        'fundamentos/01-movimentacao/01-counter-strafe.yaml: content.blocks[0].text: HTML não é permitido'],
    'campo desconhecido' => [['fundamentos.course\.yaml.color' => 'red'], 'fundamentos/course.yaml: color: campo desconhecido'],
    'key inválida' => [['fundamentos.course\.yaml.key' => 'Fundamentos CS'], 'fundamentos/course.yaml: key: use letras minúsculas'],
    'key de aula repetida no curso' => [['fundamentos.02-mira.01-posicionamento\.yaml.key' => 'peek'], 'key de aula repetido'],
    'slug de módulo repetido' => [['fundamentos.02-mira.module\.yaml.slug' => 'movimentacao'], 'slug de módulo repetido'],
    'posição de aula repetida' => [['fundamentos.01-movimentacao.02-peek\.yaml.position' => 1], 'posição de aula repetida'],
    'curso publicado sem aula publicada' => [['fundamentos.01-movimentacao.module\.yaml.status' => 'draft'],
        'fundamentos/course.yaml: status: curso publicado precisa de ao menos um módulo publicado com uma aula publicada'],
    'single_choice com duas corretas' => [['fundamentos.01-movimentacao.01-counter-strafe\.yaml.quiz.questions.0.options.0.correct' => true],
        'quiz.questions[0].options: single_choice precisa de exatamente 1 opção correta'],
    'quiz sem quiz_ref' => [['fundamentos.01-movimentacao.01-counter-strafe\.yaml.content.blocks.3' => ['type' => 'markdown', 'text' => 'fim']],
        'content.blocks: a aula declara o quiz counter-strafe-quiz'],
    'versão de conteúdo' => [['fundamentos.01-movimentacao.02-peek\.yaml.content.version' => 2], 'content.version: versão de conteúdo não suportada'],
    'YAML com chave repetida' => [['fundamentos.02-mira.module\.yaml' => "key: mira\nkey: outra\n"], 'fundamentos/02-mira/module.yaml: YAML inválido'],
    'YAML com objeto PHP' => [['fundamentos.02-mira.module\.yaml' => "key: !php/object 'O:8:\"stdClass\":0:{}'\n"], 'fundamentos/02-mira/module.yaml: YAML inválido'],
    'arquivo inesperado' => [['fundamentos.01-movimentacao.notas\.txt' => 'oi'], 'fundamentos/01-movimentacao/notas.txt: só arquivos .yaml de aula'],
]);

it('refuses multiple choice questions without a correct option', function () {
    $tree = AcademyContent::tree();
    $options = &$tree['fundamentos']['01-movimentacao']['01-counter-strafe.yaml']['quiz']['questions'][1]['options'];
    $options = array_map(fn (array $option) => [...$option, 'correct' => false], $options);

    [$exitCode, $output] = runImport($tree);

    expect($exitCode)->toBe(1)->and($output)->toContain('quiz.questions[1].options: multiple_choice precisa de ao menos 1 opção correta');
});

/** Árvore com um segundo curso, cópia do primeiro com outra key, slug e posição. */
function twoCourses(): array
{
    $tree = AcademyContent::tree();
    $second = $tree['fundamentos'];
    $second['course.yaml'] = [...$second['course.yaml'], 'key' => 'avancado', 'slug' => 'avancado', 'position' => 2];
    $quiz = &$second['01-movimentacao']['01-counter-strafe.yaml'];
    $quiz['quiz']['key'] = 'avancado-quiz';
    $quiz['content']['blocks'][3]['quiz'] = 'avancado-quiz';
    $second['02-mira']['01-posicionamento.yaml'] = [...$second['02-mira']['01-posicionamento.yaml'], 'key' => 'pre-mira', 'slug' => 'pre-mira'];

    return [...$tree, 'avancado' => $second];
}

it('stores an image used by several lessons and courses once, and allows the same lesson key in two courses', function () {
    [$exitCode, $output] = runImport(twoCourses());

    expect($exitCode)->toBe(0, $output)
        ->and(Media::query()->count())->toBe(2)
        ->and(Storage::disk('academy')->allFiles('media'))->toHaveCount(2)
        ->and(Lesson::query()->where('key', 'peek')->count())->toBe(2);
});

it('refuses a lesson moved from one course to another', function () {
    runImport(twoCourses());
    $tree = twoCourses();
    $tree['fundamentos']['01-movimentacao']['03-pre-mira.yaml'] = [...$tree['avancado']['02-mira']['01-posicionamento.yaml'], 'position' => 3];
    unset($tree['avancado']['02-mira']['01-posicionamento.yaml']);

    [$exitCode, $output] = runImport($tree);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('fundamentos/01-movimentacao/03-pre-mira.yaml: key: a aula avancado/pre-mira saiu dos arquivos daquele curso')
        ->and(Lesson::query()->where('key', 'pre-mira')->count())->toBe(1);
});

it('ignores entries starting with an underscore', function () {
    [$exitCode] = runImport(AcademyContent::treeWith(['_rascunho' => ['qualquer.yaml' => 'não é validado: [']]));

    expect($exitCode)->toBe(0)->and(Course::query()->count())->toBe(1);
});

it('simulates with --dry-run without touching the database or the disk', function () {
    [$exitCode, $output] = runImport(AcademyContent::tree(), ['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('cursos   criados 1')->toContain('mídias   registradas 2 · arquivos gravados 0')->toContain('Simulação: nada foi gravado.')
        ->and(array_sum(academyRowCounts()))->toBe(0)
        ->and(Storage::disk('academy')->allFiles())->toBe([]);
});

it('never deletes content missing from the files: it is only listed', function () {
    runImport(AcademyContent::tree());

    [$exitCode, $output] = runImport(AcademyContent::treeWith(['fundamentos.01-movimentacao.02-peek\.yaml' => null]));

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('ausentes (não alterados): aula fundamentos/peek')
        ->and(lessonByKey('peek')->status)->toBe(PublicationStatus::Published);
});

it('archives missing content only with --archive-missing and confirmation', function () {
    runImport(AcademyContent::tree());
    $path = AcademyContent::write(AcademyContent::treeWith(['fundamentos.01-movimentacao.02-peek\.yaml' => null]));

    $this->artisan('academy:import', ['path' => $path, '--archive-missing' => true])
        ->expectsOutputToContain('aula fundamentos/peek')
        ->expectsConfirmation('Arquivar esses itens?', 'no')
        ->expectsOutputToContain('Importação cancelada')
        ->assertFailed();
    expect(lessonByKey('peek')->status)->toBe(PublicationStatus::Published);

    $this->artisan('academy:import', ['path' => $path, '--archive-missing' => true])
        ->expectsConfirmation('Arquivar esses itens?', 'yes')
        ->assertSuccessful();
    expect(lessonByKey('peek')->status)->toBe(PublicationStatus::Archived)
        ->and(lessonByKey('peek')->archived_at)->not->toBeNull();

    [$exitCode] = runImport(AcademyContent::treeWith(['fundamentos.01-movimentacao.02-peek\.yaml' => null]), ['--archive-missing' => true]);
    expect($exitCode)->toBe(0); // nada novo a arquivar: não pede confirmação
});

it('allows draft to published to archived and back to published, never published to draft', function () {
    runImport(AcademyContent::tree());

    [$exitCode, $output] = runImport(AcademyContent::treeWith(['fundamentos.01-movimentacao.02-peek\.yaml.status' => 'draft']));
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('fundamentos/01-movimentacao/02-peek.yaml: status: não pode passar de published para draft');

    runImport(AcademyContent::treeWith(['fundamentos.01-movimentacao.02-peek\.yaml.status' => 'archived']));
    expect(lessonByKey('peek')->status)->toBe(PublicationStatus::Archived);

    runImport(AcademyContent::tree());
    expect(lessonByKey('peek')->only(['status', 'archived_at']))->toBe(['status' => PublicationStatus::Published, 'archived_at' => null]);
});

it('refuses slugs and positions still held by content missing from the files', function () {
    runImport(AcademyContent::tree());

    [$exitCode, $output] = runImport(AcademyContent::treeWith([
        'fundamentos.01-movimentacao.02-peek\.yaml' => null,
        'fundamentos.01-movimentacao.03-nova\.yaml' => [...AcademyContent::tree()['fundamentos']['01-movimentacao']['02-peek.yaml'], 'key' => 'nova'],
    ]));

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('03-nova.yaml: slug: slug já usado por aula peek, que não está nos arquivos')
        ->and($output)->toContain('03-nova.yaml: position: posição já usada pela aula peek');
});

it('rolls back the whole import on a database failure, leaving only harmless orphan files', function () {
    // Falha no meio da transação: depois de curso, módulos, mídias e da primeira aula.
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION academy_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'falha simulada na aula %', NEW.key; END $$;
        CREATE TRIGGER academy_test_fail BEFORE INSERT ON lessons FOR EACH ROW WHEN (NEW.key = 'peek') EXECUTE FUNCTION academy_test_fail();
    SQL);

    [$exitCode, $output] = runImport(AcademyContent::tree());

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Importação falhou e foi desfeita no banco')->toContain('falha simulada na aula peek')
        ->and(array_sum(academyRowCounts()))->toBe(0)
        // As imagens foram gravadas antes da transação e ficaram sem registro: nada aponta para elas.
        ->and(Storage::disk('academy')->allFiles('media'))->toHaveCount(2)
        ->and(Storage::disk('academy')->allFiles('tmp'))->toBe([]);

    DB::unprepared('DROP TRIGGER academy_test_fail ON lessons; DROP FUNCTION academy_test_fail();');
    [$exitCode] = runImport(AcademyContent::tree());
    expect($exitCode)->toBe(0)->and(Media::query()->count())->toBe(2)
        ->and(Storage::disk('academy')->allFiles('media'))->toHaveCount(2);
});

it('revises the quiz when grading changes, and deletes removed items of a quiz without attempts', function () {
    runImport(AcademyContent::tree());
    $quiz = Quiz::query()->sole();
    $tree = AcademyContent::tree();
    $lesson = &$tree['fundamentos']['01-movimentacao']['01-counter-strafe.yaml'];
    $lesson['quiz']['title'] = 'Novo título';

    runImport($tree);
    expect($quiz->fresh()->only(['title', 'revision']))->toBe(['title' => 'Novo título', 'revision' => 1]);

    $lesson['quiz']['questions'][0]['options'][0]['correct'] = true;
    $lesson['quiz']['questions'][0]['options'][1]['correct'] = false;
    unset($lesson['quiz']['questions'][1]['options'][2]);
    $lesson['quiz']['questions'] = [$lesson['quiz']['questions'][1], $lesson['quiz']['questions'][0]];
    [$exitCode, $output] = runImport($tree);

    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('revisão +1')
        ->and($quiz->fresh()->revision)->toBe(2)
        ->and(QuizQuestion::query()->orderBy('position')->pluck('key')->all())->toBe(['quando', 'tecla'])
        ->and(QuizOption::query()->where('key', 'rotacao')->exists())->toBeFalse()
        ->and(QuizOption::query()->where('key', 'a')->value('is_correct'))->toBeTrue();
});

it('retires, instead of deleting, removed items of a quiz that has attempts', function () {
    app()->bind(QuizUsage::class, fn () => new class implements QuizUsage
    {
        public function hasAttempts(Quiz $quiz): bool
        {
            return true;
        }
    });
    runImport(AcademyContent::tree());
    $tree = AcademyContent::tree();
    $quizTree = &$tree['fundamentos']['01-movimentacao']['01-counter-strafe.yaml']['quiz'];
    $removed = array_pop($quizTree['questions']);

    [$exitCode, $output] = runImport($tree);
    $question = QuizQuestion::query()->where('key', 'quando')->sole();
    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('itens aposentados 1 · itens removidos 0')
        ->and($question->retired_at)->not->toBeNull()
        ->and(QuizOption::query()->where('question_id', $question->id)->count())->toBe(3);

    $quizTree['questions'][] = $removed;
    runImport($tree);
    expect($question->fresh()->retired_at)->toBeNull()->and(Quiz::query()->sole()->revision)->toBe(3);

    // Trocar a key do quiz: o antigo, com tentativas, é aposentado e o novo assume a aula.
    $quizTree['key'] = 'novo-quiz';
    $tree['fundamentos']['01-movimentacao']['01-counter-strafe.yaml']['content']['blocks'][3]['quiz'] = 'novo-quiz';
    runImport($tree);
    expect(Quiz::query()->whereNull('retired_at')->pluck('key')->all())->toBe(['novo-quiz'])
        ->and(Quiz::query()->where('key', 'counter-strafe-quiz')->value('retired_at'))->not->toBeNull();
});

it('refuses a quiz key that belongs to another lesson', function () {
    runImport(AcademyContent::tree());
    $tree = AcademyContent::tree();
    $source = &$tree['fundamentos']['01-movimentacao'];
    $source['02-peek.yaml']['quiz'] = $source['01-counter-strafe.yaml']['quiz'];
    $source['02-peek.yaml']['content']['blocks'][] = ['type' => 'quiz_ref', 'quiz' => 'counter-strafe-quiz'];
    unset($source['01-counter-strafe.yaml']['quiz']);
    array_pop($source['01-counter-strafe.yaml']['content']['blocks']);

    [$exitCode, $output] = runImport($tree);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('02-peek.yaml: quiz.key: key já pertence ao quiz da aula fundamentos/counter-strafe');
});

it('does not run while another process holds the content lock', function () {
    config()->set('database.connections.lock_holder', config('database.connections.pgsql'));
    $holder = DB::connection('lock_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [ContentLock::KEY]);

    try {
        [$exitCode, $output] = runImport(AcademyContent::tree());
        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Outra importação ou limpeza de mídia da Academy está em andamento')
            ->and(array_sum(academyRowCounts()))->toBe(0)
            ->and(Storage::disk('academy')->allFiles())->toBe([]);

        $this->artisan('academy:media-prune')->expectsOutputToContain('em andamento')->assertFailed();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [ContentLock::KEY]);
        DB::purge('lock_holder');
    }

    expect(runImport(AcademyContent::tree())[0])->toBe(0);
});

// Importar é só pelo terminal (matriz de autorização): na 2.1 não havia rota da Academy; desde a
// 2.2 existem apenas as leituras do catálogo (docs/api/academy-v1.md), nenhuma que escreva.
it('exposes only the read-only catalog routes over HTTP, never the import', function () {
    $academyRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'academy'))
        ->mapWithKeys(fn ($route) => [$route->uri() => $route->methods()])
        ->sortKeys()
        ->all();

    expect($academyRoutes)->toBe([
        'api/v1/academy/courses' => ['GET', 'HEAD'],
        'api/v1/academy/courses/{course}' => ['GET', 'HEAD'],
        'api/v1/academy/courses/{course}/lessons/{lesson}' => ['GET', 'HEAD'],
        'api/v1/academy/media/{file}' => ['GET', 'HEAD'],
    ]);
});

it('releases the lock even when the import fails', function () {
    runImport(AcademyContent::treeWith(['fundamentos.course\.yaml.color' => 'red']));
    DB::unprepared("CREATE FUNCTION academy_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'falha'; END \$\$;
        CREATE TRIGGER academy_test_fail BEFORE INSERT ON courses FOR EACH ROW EXECUTE FUNCTION academy_test_fail();");
    expect(runImport(AcademyContent::tree())[0])->toBe(1);

    // O lock é reentrante na mesma sessão: quem confere é outra conexão.
    config()->set('database.connections.lock_holder', config('database.connections.pgsql'));
    $other = DB::connection('lock_holder');
    try {
        expect($other->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [ContentLock::KEY])->ok)->toBeTrue();
    } finally {
        $other->select('SELECT pg_advisory_unlock_all()');
        DB::purge('lock_holder');
    }
});
