<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| As migrations do Identity precisam ser reversíveis. O PostgreSQL executa DDL dentro de
| transações, então o rollback abaixo é desfeito ao final do teste (RefreshDatabase).
*/

const IDENTITY_TABLES = ['users', 'profiles', 'password_reset_tokens'];

it('rolls back and re-applies the identity migrations', function () {
    $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

    foreach (IDENTITY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('failed_jobs'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    foreach (IDENTITY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('creates the expected indexes and constraints', function () {
    $indexes = DB::table('pg_indexes')->whereIn('tablename', ['users', 'profiles'])->pluck('indexname')->all();
    $constraints = DB::table('pg_constraint')->pluck('conname')->all();

    expect($indexes)->toContain('users_email_lower_unique', 'users_email_index', 'profiles_nickname_lower_unique', 'profiles_user_id_unique')
        ->and($constraints)->toContain(
            'users_platform_role_check',
            'users_email_not_blank_check',
            'users_name_not_blank_check',
            'profiles_user_id_foreign',
            'profiles_nickname_format_check',
            'profiles_country_code_check',
            'profiles_timezone_not_blank_check',
        );
});

it('cascades profile deletion from the user at the database level', function () {
    $rule = DB::scalar("select confdeltype from pg_constraint where conname = 'profiles_user_id_foreign'");

    expect($rule)->toBe('c');
});
