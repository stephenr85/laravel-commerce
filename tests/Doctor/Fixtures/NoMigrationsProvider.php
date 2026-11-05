<?php

namespace Rushing\Commerce\Tests\Doctor\Fixtures;

/**
 * Registers no migrations: the audit's warn shape. Never booted; the audit reads only this file's text.
 * This base reads the provider's comments as well as its code, so nothing in this file may spell the
 * registration call, or it would count.
 */
final class NoMigrationsProvider
{
    public function configurePackage(): void
    {
        //
    }
}
