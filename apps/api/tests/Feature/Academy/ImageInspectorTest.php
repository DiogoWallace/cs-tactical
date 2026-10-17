<?php

use App\Modules\Academy\Content\ValidationErrors;
use App\Modules\Academy\Import\ImageInspector;
use App\Modules\Academy\Import\MediaStore;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AcademyContent;

/*
| Validação de imagens pelo conteúdo e gravação endereçada por hash (ADR 0019, item 9).
*/

afterEach(fn () => AcademyContent::cleanup());

/** @return array{0: mixed, 1: list<string>} */
function inspectImage(string $filename, string $contents): array
{
    $errors = new ValidationErrors;
    $image = app(ImageInspector::class)->inspect($filename, $contents, 'media/'.$filename, $errors);

    return [$image, $errors->all()];
}

it('accepts a PNG and derives hash, type, size and dimensions from the content', function () {
    $png = AcademyContent::png(7, 3);
    [$image, $errors] = inspectImage('grafico.png', $png);

    expect($errors)->toBe([])
        ->and($image->sha256)->toBe(hash('sha256', $png))
        ->and($image->mime)->toBe('image/png')
        ->and($image->bytes)->toBe(strlen($png))
        ->and([$image->width, $image->height])->toBe([7, 3])
        ->and($image->path())->toBe('media/'.substr($image->sha256, 0, 2).'/'.$image->sha256.'.png');
});

it('rejects files that are not allowed images, whatever the extension says', function (string $filename, Closure $contents, string $message) {
    [$image, $errors] = inspectImage($filename, $contents());

    expect($image)->toBeNull()->and(implode("\n", $errors))->toContain($message);
})->with([
    'SVG' => ['logo.svg', fn () => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'não é PNG, JPEG, WebP ou AVIF'],
    'SVG com extensão png' => ['logo.png', fn () => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'não é PNG, JPEG, WebP ou AVIF'],
    'HTML com extensão png' => ['pagina.png', fn () => '<html><body>oi</body></html>', 'não é PNG, JPEG, WebP ou AVIF'],
    'GIF' => ['anima.gif', fn () => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'não é PNG, JPEG, WebP ou AVIF'],
    'PNG truncado' => ['quebrado.png', fn () => substr(AcademyContent::png(), 0, 20), 'não é PNG, JPEG, WebP ou AVIF'],
    'vazio' => ['vazio.png', fn () => '', 'imagem vazia'],
    'extensão diferente do conteúdo' => ['foto.webp', fn () => AcademyContent::png(), 'extensão .webp não corresponde ao conteúdo (image/png)'],
    'largura acima do limite' => ['larga.png', fn () => AcademyContent::png(4097, 1), 'dimensões 4097x1 fora do limite de 4096 px'],
]);

it('rejects images above the size limit', function () {
    config()->set('academy.media.max_bytes', 100);

    expect(inspectImage('grande.png', AcademyContent::png(20, 20))[1][0])->toContain('maior que');
});

it('writes each image once, at its hash path, through a verified temporary file', function () {
    Storage::fake('academy');
    $disk = Storage::disk('academy');
    [$image] = inspectImage('grafico.png', AcademyContent::png(5, 5, 9));
    $store = app(MediaStore::class);

    expect($store->put($image))->toBeTrue()
        ->and($disk->get($image->path()))->toBe($image->contents)
        ->and($disk->allFiles('tmp'))->toBe([])
        ->and($store->put($image))->toBeFalse();
});

it('refuses to trust an existing file whose content does not match its hash', function () {
    Storage::fake('academy');
    [$image] = inspectImage('grafico.png', AcademyContent::png(5, 5, 9));
    Storage::disk('academy')->put($image->path(), 'corrompido');

    expect(fn () => app(MediaStore::class)->put($image))->toThrow(RuntimeException::class, 'conteúdo diferente do hash');
});
