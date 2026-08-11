<?php

use Rushing\Commerce\Doctor\CommerceMigrationsAudit;
use Rushing\Doctor\DoctorStatus;

/**
 * This package's own operator check: its migrations must stay publish-only .stub files,
 * never runtime-loaded via loadMigrationsFrom() over its own vendor source.
 */
it('ships publish-only stub migrations', function () {
    $findings = (new CommerceMigrationsAudit)->run();

    foreach ($findings as $finding) {
        expect($finding->status)->not->toBe(DoctorStatus::Fail, "[{$finding->check}] {$finding->detail}");
    }
});
