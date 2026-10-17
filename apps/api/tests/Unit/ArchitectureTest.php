<?php

/*
| Testes de arquitetura: impõem as convenções de docs/architecture/visao-geral.md.
| Ao criar um módulo, adicione as regras equivalentes para ele.
*/

arch('no debugging functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('env() is only called from config files')
    ->expect('env')
    ->not->toBeUsedIn('App');

arch('System controllers do not query the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn('App\Modules\System\Http');

arch('System actions do not depend on the HTTP layer')
    ->expect('App\Modules\System\Actions')
    ->not->toUse(['Illuminate\Http\Request', 'Illuminate\Http\JsonResponse']);

arch('Identity models are Eloquent models')
    ->expect('App\Modules\Identity\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('Identity enums are backed enums')
    ->expect('App\Modules\Identity\Enums')
    ->toBeStringBackedEnums();

arch('Identity controllers do not query the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn('App\Modules\Identity\Http');

arch('Identity actions do not depend on the HTTP layer')
    ->expect('App\Modules\Identity\Actions')
    ->not->toUse(['Illuminate\Http\Request', 'Illuminate\Http\JsonResponse']);

arch('Academy models are Eloquent models')
    ->expect('App\Modules\Academy\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('Academy enums are backed enums')
    ->expect('App\Modules\Academy\Enums')
    ->toBeStringBackedEnums();

// Fronteira (plano da 2.1): só a própria Academy usa os models dela; o Learning entra na 2.4.
arch('only the Academy module uses Academy models')
    ->expect('App\Modules\Academy\Models')
    ->toOnlyBeUsedIn(['App\Modules\Academy', 'Database\Factories\Academy']);

// ADR 0015: o Learning depende da Academy, nunca o contrário (o uso de quizzes vem por contrato).
arch('Academy does not depend on Learning, Teams or Identity')
    ->expect('App\Modules\Academy')
    ->not->toUse(['App\Modules\Learning', 'App\Modules\Teams', 'App\Modules\Identity']);

arch('Academy content schemas are pure: no database, files or HTTP')
    ->expect('App\Modules\Academy\Content')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Storage', 'Illuminate\Database', 'Illuminate\Http', 'App\Modules\Academy\Models']);

// ADR 0015 (consequências): controllers públicos não consultam models; tudo passa pelo
// CatalogQuery, que aplica o escopo de visibilidade.
arch('Academy controllers neither query the database nor touch models directly')
    ->expect('App\Modules\Academy\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database', 'App\Modules\Academy\Models', 'Illuminate\Support\Facades\Storage']);

arch('Academy HTTP layer does not run the importer')
    ->expect('App\Modules\Academy\Http')
    ->not->toUse(['App\Modules\Academy\Import\ContentImporter', 'App\Modules\Academy\Import\ContentParser', 'App\Modules\Academy\Console']);

// Fase (a) da importação (ADR 0016): validar tudo sem gravar nada.
arch('the content parser neither touches the database nor the media disk')
    ->expect(['App\Modules\Academy\Import\ContentParser', 'App\Modules\Academy\Import\CourseMedia', 'App\Modules\Academy\Import\ImageInspector'])
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Storage', 'App\Modules\Academy\Models']);

arch('the legacy App\Models namespace is not used')
    ->expect('App\Models')
    ->not->toBeUsed();

/*
| Autorização (ADR 0012). Os outros módulos autorizam por Gates (capacidades) e Policies;
| o papel de plataforma é um detalhe do Identity.
*/
arch('only the Identity module knows platform roles')
    ->expect('App\Modules\Identity\Enums\PlatformRole')
    ->toOnlyBeUsedIn(['App\Modules\Identity', 'Database\Factories\Identity']);

arch('policies are classes named *Policy inside a module')
    ->expect('App\Modules\Identity\Policies')
    ->toBeClasses()
    ->toHaveSuffix('Policy');

arch('controllers do not read the platform role')
    ->expect('App\Modules\Identity\Http\Controllers')
    ->not->toUse('App\Modules\Identity\Enums\PlatformRole');

it('has no Gate::before or Gate::after shortcut that bypasses policies', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match('/Gate::(before|after)\s*\(|->(before|after)\s*\(\s*(static\s+)?(fn|function)/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('compares platform_role only inside the Identity module', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app'));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->getExtension() !== 'php' || str_contains($path, '/Modules/Identity/')) {
            continue;
        }
        // Só código: comentários podem citar o campo.
        $code = collect(token_get_all((string) file_get_contents($file->getPathname())))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        if (str_contains($code, 'platform_role')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

// Exceção única: o IdentityServiceProvider monta o link de verificação de e-mail com
// sha1(email), formato exigido pelo Laravel/Fortify (VerifyEmailRequest compara esse valor).
// O sha1 não protege nada ali: a integridade vem da assinatura HMAC-SHA256 da URL.
arch()->preset()->security()->ignoring('App\Modules\Identity\IdentityServiceProvider');
