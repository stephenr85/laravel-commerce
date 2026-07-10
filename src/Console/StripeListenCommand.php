<?php

namespace Rushing\Commerce\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Forwards live Stripe events to this app's local webhook endpoint for development,
 * authenticating the Stripe CLI with the app's own `commerce.stripe.secret` so the key is
 * never copy-pasted or re-parsed out of .env in each consumer.
 *
 * A deliberately thin wrapper over `stripe listen`: local forwarding needs the Stripe CLI's
 * websocket tunnel to Stripe, which no PHP process can replicate — so if the CLI is not
 * installed its absence is surfaced with an install hint rather than worked around. Shipping
 * this with the money-in engine gives the Splicewire platform and every satellite that
 * adopts it one canonical `commerce:stripe-listen`, instead of a hand-copied shell script.
 */
class StripeListenCommand extends Command
{
    protected $signature = 'commerce:stripe-listen
        {--path= : Webhook path to forward to (defaults to config commerce.stripe.webhook_path)}
        {--forward-to= : Full URL to forward to, overriding the derived app URL + path}
        {--print-secret : Print the webhook signing secret and exit, without forwarding}';

    protected $description = 'Forward Stripe webhooks to this app locally via the Stripe CLI (dev only).';

    public function handle(): int
    {
        $secret = config('commerce.stripe.secret');

        if (blank($secret)) {
            $this->error('commerce.stripe.secret (STRIPE_SECRET) is not set — nothing to authenticate the Stripe CLI with.');

            return self::FAILURE;
        }

        $binary = (new ExecutableFinder)->find('stripe');

        if ($binary === null) {
            $this->error('The Stripe CLI ("stripe") was not found on PATH.');
            $this->line('Install it — https://docs.stripe.com/stripe-cli — then re-run this command.');

            return self::FAILURE;
        }

        $args = [$binary, 'listen', '--api-key', (string) $secret];

        if ($this->option('print-secret')) {
            $args[] = '--print-secret';
        } else {
            $url = self::resolveForwardUrl(
                (string) config('app.url'),
                $this->option('forward-to') ? (string) $this->option('forward-to') : null,
                (string) ($this->option('path') ?: config('commerce.stripe.webhook_path', 'stripe/webhook')),
            );
            $args[] = '--forward-to';
            $args[] = $url;
            $this->info("Forwarding Stripe webhooks → {$url}");
        }

        // Long-running listener: no timeout, and stream the CLI's output straight through so
        // the webhook secret and each forwarded event (and any error) are visible live.
        $process = (new Process($args))->setTimeout(null);

        return $process->run(fn ($type, $buffer) => $this->output->write($buffer));
    }

    /**
     * Join an app base URL and a webhook path into a single forward target. A caller may
     * pass a fully-formed override as $override (used as-is); otherwise the path is joined
     * onto the base. Pure and side-effect-free so URL derivation is unit-testable without
     * spawning the CLI.
     */
    public static function resolveForwardUrl(string $appUrl, ?string $override, string $path = 'stripe/webhook'): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        return rtrim($appUrl, '/').'/'.ltrim($path, '/');
    }
}
