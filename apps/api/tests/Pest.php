<?php

use Tests\TestCase;

/*
| Testes em tests/Feature sobem a aplicação Laravel completa.
| Testes em tests/Unit são PHP puro, sem framework.
| Quando houver tabelas de domínio, adicione RefreshDatabase aqui:
|   ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
*/
pest()->extend(TestCase::class)->in('Feature');
