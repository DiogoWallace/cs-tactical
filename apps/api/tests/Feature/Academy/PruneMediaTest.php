<?php

use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AcademyContent;

/*
| academy:media-prune (ADR 0019, item 14): remove só órfãos com mais de 24 horas, reconferindo
| cada arquivo antes; nunca o que tem registro ou é citado por uma aula.
*/

beforeEach(fn () => Storage::fake('academy'));
afterEach(fn () => AcademyContent::cleanup());

function mediaFile(int $seed, int $ageHours): string
{
    $contents = AcademyContent::png(3, 3, $seed);
    $sha = hash('sha256', $contents);
    $path = 'media/'.substr($sha, 0, 2)."/{$sha}.png";
    $disk = Storage::disk('academy');
    $disk->put($path, $contents);
    touch($disk->path($path), time() - $ageHours * 3600);

    return $path;
}

it('removes orphan files older than 24 hours and keeps recent ones', function () {
    $old = mediaFile(1, 25);
    $recent = mediaFile(2, 23);
    $staleTmp = 'tmp/interrompido.png';
    Storage::disk('academy')->put($staleTmp, 'x');
    touch(Storage::disk('academy')->path($staleTmp), time() - 30 * 3600);

    $this->artisan('academy:media-prune', ['--dry-run' => true])
        ->expectsOutputToContain("removeria {$old}")
        ->expectsOutputToContain('Simulação: nada foi removido.')
        ->assertSuccessful();
    expect(Storage::disk('academy')->exists($old))->toBeTrue();

    $this->artisan('academy:media-prune')
        ->expectsOutputToContain('órfãos removidos: 2')
        ->expectsOutputToContain('com menos de 24 h 1')
        ->assertSuccessful();

    expect(Storage::disk('academy')->exists($old))->toBeFalse()
        ->and(Storage::disk('academy')->exists($staleTmp))->toBeFalse()
        ->and(Storage::disk('academy')->exists($recent))->toBeTrue();
});

it('never removes files registered in academy_media, however old', function () {
    Artisan::call('academy:import', ['path' => AcademyContent::write(AcademyContent::tree())]);
    $disk = Storage::disk('academy');
    foreach ($disk->allFiles('media') as $path) {
        touch($disk->path($path), time() - 100 * 24 * 3600);
    }

    $this->artisan('academy:media-prune')->expectsOutputToContain('em uso 2')->assertSuccessful();

    expect(Media::query()->pluck('path')->sort()->values()->all())->toBe(collect($disk->allFiles('media'))->sort()->values()->all());
});

it('keeps and reports a file cited by a lesson without a media row', function () {
    $path = mediaFile(3, 48);
    $sha = basename($path, '.png');
    Lesson::factory()->create(['content' => ['version' => 1, 'blocks' => [
        ['type' => 'image', 'data' => ['media' => $sha, 'alt' => 'x', 'decorative' => false, 'caption' => null]],
    ]]]);

    $this->artisan('academy:media-prune')
        ->expectsOutputToContain("anomalia (aula cita o arquivo sem registro; mantido): {$path}")
        ->assertFailed();
    expect(Storage::disk('academy')->exists($path))->toBeTrue();
});

it('leaves files outside the naming scheme alone', function () {
    Storage::disk('academy')->put('media/zz/estranho.png', 'x');
    touch(Storage::disk('academy')->path('media/zz/estranho.png'), time() - 48 * 3600);

    $this->artisan('academy:media-prune')->expectsOutputToContain('arquivo fora do padrão (mantido, verifique): media/zz/estranho.png')->assertSuccessful();
    expect(Storage::disk('academy')->exists('media/zz/estranho.png'))->toBeTrue();
});

it('cleans up the orphans of a failed import once they are 24 hours old', function () {
    DB::unprepared("CREATE FUNCTION academy_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'falha'; END \$\$;
        CREATE TRIGGER academy_test_fail BEFORE INSERT ON lessons FOR EACH ROW EXECUTE FUNCTION academy_test_fail();");
    expect(Artisan::call('academy:import', ['path' => AcademyContent::write(AcademyContent::tree())]))->toBe(1);
    $disk = Storage::disk('academy');
    $orphans = $disk->allFiles('media');
    expect($orphans)->toHaveCount(2)->and(Media::query()->count())->toBe(0);

    $this->artisan('academy:media-prune')->expectsOutputToContain('órfãos removidos: 0')->assertSuccessful();
    foreach ($orphans as $path) {
        touch($disk->path($path), time() - 25 * 3600);
    }
    $this->artisan('academy:media-prune')->expectsOutputToContain('órfãos removidos: 2')->assertSuccessful();

    expect($disk->allFiles('media'))->toBe([]);
});
