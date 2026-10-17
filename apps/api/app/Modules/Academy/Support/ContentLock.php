<?php

namespace App\Modules\Academy\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Advisory lock do PostgreSQL que serializa `academy:import` e `academy:media-prune` (ADR 0016,
 * ADR 0019). Lock de sessão, não espera: se outro processo tem o lock, falha na hora.
 */
final class ContentLock
{
    /** Constante arbitrária e fixa ("academy" em ASCII). */
    public const KEY = 0x61636164656D79;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $acquired = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [self::KEY])->acquired;
        if ($acquired !== true) {
            throw new ContentLockedException;
        }

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?)', [self::KEY]);
        }
    }
}
