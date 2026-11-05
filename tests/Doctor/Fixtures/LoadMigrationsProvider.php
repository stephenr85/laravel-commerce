<?php

namespace Rushing\Commerce\Tests\Doctor\Fixtures;

/**
 * Runtime-loads migrations from its own package source: the audit's first fail shape. Never booted;
 * the audit reads only this file's text.
 */
final class LoadMigrationsProvider
{
    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
