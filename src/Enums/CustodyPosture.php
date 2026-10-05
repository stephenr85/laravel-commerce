<?php

namespace Rushing\Commerce\Enums;

/**
 * Who holds the money for this host (purchase-walkthrough BUY-4): `none` offers no purchase at all, `hosted` collects
 * through a provider-hosted leg, `direct` charges in-app. Declared by `commerce.custody`; the rail (`commerce.driver`)
 * never changes it.
 */
enum CustodyPosture: string
{
    case None = 'none';
    case Hosted = 'hosted';
    case Direct = 'direct';
}
