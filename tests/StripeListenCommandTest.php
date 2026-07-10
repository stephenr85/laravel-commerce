<?php

use Rushing\Commerce\Console\StripeListenCommand;

it('joins the app url and configured path into a forward target', function () {
    expect(StripeListenCommand::resolveForwardUrl('https://numero.test/', null))
        ->toBe('https://numero.test/stripe/webhook')
        ->and(StripeListenCommand::resolveForwardUrl('https://x.test', null, 'hooks/stripe'))
        ->toBe('https://x.test/hooks/stripe');
});

it('uses a full forward-to override verbatim, ignoring the path', function () {
    expect(StripeListenCommand::resolveForwardUrl('https://x.test', 'https://tunnel.dev/wh', 'stripe/webhook'))
        ->toBe('https://tunnel.dev/wh');
});

it('fails with a clear message when no stripe secret is configured', function () {
    config()->set('commerce.stripe.secret', null);

    $this->artisan('commerce:stripe-listen')
        ->expectsOutputToContain('commerce.stripe.secret')
        ->assertFailed();
});
