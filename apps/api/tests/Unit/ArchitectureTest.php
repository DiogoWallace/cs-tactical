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

// Exceção única: o IdentityServiceProvider monta o link de verificação de e-mail com
// sha1(email), formato exigido pelo Laravel/Fortify (VerifyEmailRequest compara esse valor).
// O sha1 não protege nada ali: a integridade vem da assinatura HMAC-SHA256 da URL.
arch()->preset()->security()->ignoring('App\Modules\Identity\IdentityServiceProvider');
