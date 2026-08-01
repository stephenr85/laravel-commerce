<?php

/**
 * The neutral engine's `Contracts/` surface names no payment provider (ADR-0003).
 * Provider-specific contracts belong in a provider-scoped namespace (`Stripe/`),
 * never here — so a `use Stripe\` import can never cross the agnostic boundary.
 */
it('has no Stripe imports under src/Contracts/', function () {
    $dir = dirname(__DIR__).'/src/Contracts';

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/^\s*use\s+Stripe\\\\/m', file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace($dir.'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe(
        [],
        'Provider-specific contracts must live in a provider-scoped namespace (e.g. Rushing\Commerce\Stripe\), '
        .'not the neutral Contracts/ surface. Offending files: '.implode(', ', $offenders)
    );
});
