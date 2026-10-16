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
