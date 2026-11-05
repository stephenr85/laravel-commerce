<?php

namespace Rushing\Commerce\Tests\Doctor\Fixtures\RealPhp\Src;

/**
 * Registers its migrations, and still ships a real (non-`.stub`) `.php` migration in
 * `../database/migrations`: the audit's second fail shape. Never booted; the audit reads only this
 * file's text, so the registration only has to be written as a call.
 */
final class RealPhpProvider
{
    public function configurePackage(object $package): void
    {
        $package->hasMigrations(['create_bar_table']);
    }
}
