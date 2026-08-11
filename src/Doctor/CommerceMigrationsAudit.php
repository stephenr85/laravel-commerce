<?php

namespace Rushing\Commerce\Doctor;

use Rushing\Commerce\CommerceServiceProvider;
use Rushing\Commerce\Doctor\Support\StubMigrationsAudit;

class CommerceMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'rushing/laravel-commerce';
    }

    protected function serviceProviderClass(): string
    {
        return CommerceServiceProvider::class;
    }
}
