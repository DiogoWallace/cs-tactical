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

arch()->preset()->security();
