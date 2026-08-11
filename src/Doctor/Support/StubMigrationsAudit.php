<?php

namespace Rushing\Commerce\Doctor\Support;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The doctor/operator self-check for the publish-only migrations convention this package
 * follows: migrations ship as timestamp-less `.php.stub` files, registered via
 * spatie/laravel-package-tools' `->hasMigrations([...])`, never auto-loaded at runtime
 * (`loadMigrationsFrom()`). `vendor:publish --tag=commerce-migrations` re-stamps + sequences
 * them into the host at install time.
 *
 * A rushing-tier local of the same shape the `splicewire/laravel-beam` family runs its own
 * migrations audits on ({@see \Splicewire\Beam\Doctor\Support\StubMigrationsAudit}) — this
 * package deliberately does NOT depend on `splicewire/laravel-beam` (it is the neutral
 * `rushing/*` engine tier, consumed by hosts that may or may not be beam-family), so the
 * mechanical check is duplicated here rather than pulling in that dependency. Both build on
 * the same moat-free foundation ({@see DoctorAudit}, {@see Finding} from `rushing/laravel-doctor`).
 *
 * A subclass names its own package + {@see PackageServiceProvider}. Purely mechanical
 * (source-text + filesystem inspection), zero semantic judgment: fails on a stray
 * `loadMigrationsFrom(` over the package's OWN vendor source, warns when `->hasMigrations(`
 * is absent, fails on a real (non-`.stub`) `.php` migration file sitting alongside the stubs.
 */
abstract class StubMigrationsAudit implements DoctorAudit
{
    abstract protected function packageName(): string;

    /**
     * @return class-string
     */
    abstract protected function serviceProviderClass(): string;

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = "migrations are publish-only stubs ({$this->packageName()})";

        $providerFile = (new ReflectionClass($this->serviceProviderClass()))->getFileName();

        if ($providerFile === false) {
            return [Finding::warn($check, "could not resolve a source file for {$this->serviceProviderClass()}.")];
        }

        $source = file_get_contents($providerFile);

        if ($source === false) {
            return [Finding::warn($check, "could not read source for {$this->serviceProviderClass()}.")];
        }

        foreach ($this->offendingLoadMigrationsFromCalls($source) as $call) {
            return [Finding::fail(
                $check,
                "{$this->serviceProviderClass()} calls loadMigrationsFrom({$call}) on its own package source — ".
                'migrations auto-run at runtime instead of shipping publish-only, per the publish-only stub '.
                'convention.',
            )];
        }

        if (! str_contains($source, 'hasMigrations(')) {
            return [Finding::warn(
                $check,
                "{$this->serviceProviderClass()} registers no ->hasMigrations([...]) in configurePackage() — ".
                'migrations may not be publish-only stubs (the convention: timestamp-less .php.stub, '.
                're-stamped on vendor:publish via package-tools).',
            )];
        }

        $migrationsDir = dirname($providerFile).'/../database/migrations';

        if (is_dir($migrationsDir)) {
            $realPhp = array_merge(
                glob($migrationsDir.'/*.php') ?: [],
                glob($migrationsDir.'/*/*.php') ?: [],
            );

            if ($realPhp !== []) {
                return [Finding::fail(
                    $check,
                    'real (non-.stub) .php migration file(s) found — should be timestamp-less .php.stub, '.
                    're-stamped into the host on publish: '.implode(', ', array_map('basename', $realPhp)),
                )];
            }
        }

        return [Finding::pass(
            $check,
            "{$this->serviceProviderClass()} ships publish-only .stub migrations via ->hasMigrations([...]), ".
            'no loadMigrationsFrom() over its own source.',
        )];
    }

    /**
     * Every `loadMigrationsFrom(...)` call site in $source whose argument does NOT resolve to
     * `database_path(...)` — the one sanctioned host-glue exception (registering an
     * ALREADY-published directory, not a package auto-loading its own un-published
     * migrations). Still a mechanical text trace, not semantic evaluation.
     *
     * @return list<string> the raw argument text of each offending call, for the Finding detail
     */
    private function offendingLoadMigrationsFromCalls(string $source): array
    {
        // Require the `->` call syntax so a docblock/comment merely NAMING loadMigrationsFrom() in
        // prose (this class's own docblock does exactly that) never counts as a call site.
        if (! preg_match_all('/->loadMigrationsFrom\(([^)]*)\)/', $source, $matches)) {
            return [];
        }

        return array_values(array_filter(
            $matches[1],
            fn (string $argument): bool => ! $this->resolvesToDatabasePath($argument, $source),
        ));
    }

    private function resolvesToDatabasePath(string $argument, string $source): bool
    {
        if (str_contains($argument, 'database_path(')) {
            return true;
        }

        $variable = trim($argument);

        if (! preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $variable)) {
            return false;
        }

        return (bool) preg_match(
            '/'.preg_quote($variable, '/').'\s*=\s*database_path\(/',
            $source,
        );
    }
}
