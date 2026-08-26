<?php

namespace Rushing\Commerce\Acp\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\Commerce\Acp\AgenticCheckout;
use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Data\CheckoutSessionCompleteInput;
use Rushing\Commerce\Acp\Data\CheckoutSessionCreateInput;
use Rushing\Commerce\Acp\Exceptions\CheckoutException;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;

/**
 * The ACP Agentic Checkout HTTP boundary. Responses are the **protocol shape**
 * (the checkout session object itself), not the app's `{data}` envelope — an
 * external agent consumes ACP, not our SPA contract. The host mounts these under
 * its own public tenant group via `Route::commerceAcpRoutes()`.
 *
 * The request bodies are declared by {@see CheckoutSessionCreateInput} / {@see CheckoutSessionCompleteInput}
 * so the published document and the generated client carry the protocol's own field names and prose
 * (api-surface-coherence ticket 64). `#[RequestFromData]` ships in `rushing/laravel-data-schemas-scribe`,
 * which is a **require-dev** here on purpose: this is an open foundation package and that dependency drags
 * `knuckleswtf/scribe` in behind it, so a consumer that only wants the commerce engine should not pay for a
 * documentation toolchain. A PHP attribute is inert unless something reflects it, so the annotation costs
 * nothing at runtime in a host that lacks the package, and resolves normally in one that has it.
 */
class CheckoutSessionController extends Controller
{
    public function __construct(private AgenticCheckout $checkout) {}

    #[RequestFromData(CheckoutSessionCreateInput::class)]
    public function create(Request $request): JsonResponse
    {
        // Hydrated, never injected as a typed parameter (api-surface-coherence ticket 27).
        $input = CheckoutSessionCreateInput::validateAndCreate($request);

        try {
            $session = $this->checkout->create(
                items: $input->engineItems(),
                provenance: $this->provenance($request),
                currency: is_string($input->currency) ? $input->currency : null,
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

    #[RequestFromData(CheckoutSessionCompleteInput::class)]
    public function complete(Request $request, string $id): JsonResponse
    {
        $payment = CheckoutSessionCompleteInput::validateAndCreate($request)->payment();

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
}
