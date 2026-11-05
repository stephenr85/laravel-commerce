<?php

use Rushing\Commerce\CommerceServiceProvider;
use Rushing\Commerce\Doctor\Support\StubMigrationsAudit;
use Rushing\Commerce\Tests\Doctor\Fixtures\LoadMigrationsProvider;
use Rushing\Commerce\Tests\Doctor\Fixtures\NoMigrationsProvider;
use Rushing\Commerce\Tests\Doctor\Fixtures\RealPhp\Src\RealPhpProvider;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;

/**
 * This package's own operator check: its migrations must stay publish-only .stub files,
 * never runtime-loaded via loadMigrationsFrom() over its own vendor source.
 */
it('ships publish-only stub migrations', function () {
    $findings = (new StubMigrationsAudit('rushing/laravel-commerce', CommerceServiceProvider::class))->run();

    foreach ($findings as $finding) {
        expect($finding->status)->not->toBe(DoctorStatus::Fail, "[{$finding->check}] {$finding->detail}");
    }
});

/**
 * Lead ruling A (make-surface 04, 2026-11-05): the base became concrete, and the two-method subclass it
 * replaced is gone. The findings must not change. These cases pin each finding field for field: the one
 * the subclass reported for this package, and the base's three other shapes over fixture providers. The
 * base has no inconclusive shape.
 *
 * @return array{DoctorStatus, string, string, bool}
 */
function stubMigrationsFindingFields(Finding $finding): array
{
    return [$finding->status, $finding->check, $finding->detail, $finding->conclusive];
}

it('reports for this package exactly the finding the retired subclass reported', function () {
    $findings = (new StubMigrationsAudit('rushing/laravel-commerce', CommerceServiceProvider::class))->run();

    // CommerceServiceProvider::configurePackage() makes a real ->hasMigrations([...]) call, and
    // database/migrations holds only .php.stub files.
    expect(array_map(stubMigrationsFindingFields(...), $findings))->toBe([[
        DoctorStatus::Pass,
        'migrations are publish-only stubs (rushing/laravel-commerce)',
        CommerceServiceProvider::class.' ships publish-only .stub migrations via ->hasMigrations([...]), '.
        'no loadMigrationsFrom() over its own source.',
        true,
    ]]);
});

it('fails a provider that runtime-loads migrations from its own source', function () {
    $findings = (new StubMigrationsAudit('fixture/load', LoadMigrationsProvider::class))->run();

    expect(array_map(stubMigrationsFindingFields(...), $findings))->toBe([[
        DoctorStatus::Fail,
        'migrations are publish-only stubs (fixture/load)',
        LoadMigrationsProvider::class." calls loadMigrationsFrom(__DIR__.'/database/migrations') on its own package ".
        'source — migrations auto-run at runtime instead of shipping publish-only, per the publish-only stub '.
        'convention.',
        true,
    ]]);
});

it('warns on a provider that registers no migrations', function () {
    $findings = (new StubMigrationsAudit('fixture/none', NoMigrationsProvider::class))->run();

    expect(array_map(stubMigrationsFindingFields(...), $findings))->toBe([[
        DoctorStatus::Warn,
        'migrations are publish-only stubs (fixture/none)',
        NoMigrationsProvider::class.' registers no ->hasMigrations([...]) in configurePackage() — '.
        'migrations may not be publish-only stubs (the convention: timestamp-less .php.stub, '.
        're-stamped on vendor:publish via package-tools).',
        true,
    ]]);
});

it('fails a real (non-.stub) .php migration beside the registration', function () {
    $findings = (new StubMigrationsAudit('fixture/real-php', RealPhpProvider::class))->run();

    expect(array_map(stubMigrationsFindingFields(...), $findings))->toBe([[
        DoctorStatus::Fail,
        'migrations are publish-only stubs (fixture/real-php)',
        'real (non-.stub) .php migration file(s) found — should be timestamp-less .php.stub, '.
        're-stamped into the host on publish: 2024_01_01_000000_create_bar_table.php',
        true,
    ]]);
});
