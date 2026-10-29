<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider callbacks: POST /webhooks/payments/{provider}. CSRF does not
 * apply (the provider is not a browser); the signature is the
 * authentication. Answers 2xx only once the event is safely handled, so
 * the provider retries anything that failed.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProcessPaymentWebhook $process): JsonResponse
    {
        /** @var array<string, list<string>> $headers */
        $headers = array_map(
            fn ($values): array => array_values(array_filter((array) $values, 'is_string')),
            $request->headers->all(),
        );

        try {
            $result = $process->handle($provider, $request->getContent(), $headers);
        } catch (InvalidWebhookSignature $e) {
            return response()->json(['error' => 'invalid_signature'], 400);
        } catch (PaymentProviderException $e) {
            report($e);

            return response()->json(['error' => 'unprocessable'], 422);
        }

        return response()->json(['status' => $result]);
    }
}
