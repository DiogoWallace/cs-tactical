<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Testes em tests/Feature sobem a aplicação Laravel completa, com o banco cs_tactical_test
| migrado uma vez e cada teste dentro de uma transação desfeita ao final (RefreshDatabase).
| Testes em tests/Unit são PHP puro, sem framework.
*/
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
