<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Testes em tests/Feature sobem a aplicação Laravel completa, com o banco cs_tactical_test
| migrado uma vez e cada teste dentro de uma transação desfeita ao final (RefreshDatabase).
| Testes em tests/Unit são PHP puro, sem framework.
*/
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * Quantas migrations existem a partir de `$migration` (inclusive), para os testes de rollback por
 * passos: cada migration nova entra na conta sem mudar número fixo nos testes.
 */
function migrationsFrom(string $migration): int
{
    return DB::table('migrations')->where('migration', '>=', $migration)->count();
}
