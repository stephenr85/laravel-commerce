<?php

namespace Rushing\Commerce\Acp\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Data\DelegatePayment;
use Rushing\Commerce\Acp\Exceptions\CheckoutException;
use Rushing\Commerce\Data\BillingAddress;

/**
 * The ACP Agentic Checkout HTTP boundary. Responses are the **protocol shape**
 * (the checkout session object itself), not the app's `{data}` envelope — an
 * external agent consumes ACP, not our SPA contract. The host mounts these under
 * its own public tenant group via `Route::commerceAcpRoutes()`.
 */
class CheckoutSessionController extends Controller
{
    public function __construct(private AgenticCheckout $checkout) {}

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_ref' => ['required', 'string'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        try {
            $session = $this->checkout->create(
                items: $validated['items'],
                provenance: $this->provenance($request),
                currency: $validated['currency'] ?? null,
            );
        } catch (CheckoutException $e) {
            return $this->error($e);
        }

        return $this->session($session, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->checkout->retrieve($id);

        if ($session === null) {
            return $this->error(CheckoutException::unknownSession($id));
        }

        return $this->session($session);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'payment_data.token' => ['required', 'string'],
            'payment_data.provider' => ['required', 'string'],
        ]);

        $payment = new DelegatePayment(
            token: $validated['payment_data']['token'],
            provider: $validated['payment_data']['provider'],
            billingAddress: $this->billingAddress($request),
        );

        try {
            $session = $this->checkout->complete($id, $payment, $this->provenance($request));
        } catch (CheckoutException $e) {
            return $this->error($e);
        }

        return $this->session($session);
    }

    private function session(CheckoutSession $session, int $status = 200): JsonResponse
    {
        return response()->json($session->toArray(), $status);
    }

    private function error(CheckoutException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ],
        ], $e->status);
    }

    /**
     * The agent-provenance trace, read from ACP-conventional request headers with a
     * `reason` fallback in the body — which agent, on which session, for what reason.
     */
    private function provenance(Request $request): AgentProvenance
    {
        return new AgentProvenance(
            agentId: $request->header('X-Agent-Id'),
            sessionId: $request->header('X-Agent-Session-Id'),
            reason: $request->input('reason', $request->header('X-Agent-Reason')),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id') ?? $request->header('Request-Id'),
        );
    }

    private function billingAddress(Request $request): ?BillingAddress
    {
        $address = $request->input('payment_data.billing_address');

        return is_array($address) ? BillingAddress::from($address) : null;
    }
}
