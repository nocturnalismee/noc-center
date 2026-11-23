<?php

namespace App\Http\Controllers\Api;

use App\Alerts\AlertEngine;
use App\Http\Controllers\Controller;
use App\Models\ServerToken;
use App\Services\Ingest\StoreSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class IngestController extends Controller
{
    /**
     * `application/json` followed by the end of the type or by a `;`
     * parameter list. The middleware accepts a slightly looser form and only
     * runs before authentication, so the controller re-checks the type rather
     * than trusting it (R36.1).
     */
    private const JSON_MEDIA_TYPE = '/\Aapplication\/json(?:\s*;\s*(?:[^;,"\s]+\s*=\s*(?:"[^"]*"|[^;,"\s]*)\s*)?)*\z/i';

    public function __construct(
        private readonly StoreSample $samples,
        private readonly AlertEngine $alerts,
    ) {
        //
    }

    /**
     * Validate and store an agent sample.
     *
     * Status codes (spec §8):
     * - 202 accepted, including an idempotent retry of the same sample;
     * - 400 invalid JSON or a malformed transport header;
     * - 413 payload too large;
     * - 415 unsupported media type;
     * - 422 schema/business violations, with one structured error per field.
     *
     * Every response is `Cache-Control: no-store`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (($error = $this->transportError($request)) !== null) {
            return $this->error($error[0], $error[1]);
        }

        /** @var ServerToken $token */
        $token = $request->attributes->get('noc_token');

        try {
            $result = $this->samples->storeRaw($token, $this->body($request));
        } catch (JsonException) {
            return $this->error('invalid_json', 400);
        } catch (ValidationException $exception) {
            return $this->error('unprocessable_entity', 422, $exception->errors());
        } catch (HttpExceptionInterface $exception) {
            // A deliberate `abort()`/`HttpException` carries an intended
            // status (for example 429); keep it, with the same no-store and
            // no-internals rules as every other ingest response. A wrapped
            // exception may hide a cause, so its message is never echoed.
            $body = $exception->getPrevious() === null
                ? ['error' => 'http_error', 'message' => $exception->getMessage()]
                : ['error' => 'http_error'];

            return $this->respond($body, $exception->getStatusCode());
        } catch (Throwable $exception) {
            // Never let an unhandled failure escape: the framework's renderer
            // would answer 500 without `Cache-Control: no-store`, and its body
            // can carry internals. Report it, answer a generic JSON 500.
            report($exception);

            return $this->error('server_error', 500);
        }

        // Alerting runs after the sample transaction committed and can never
        // change the response (spec §9.4): a rule-engine or queue failure is
        // reported, the alerting state is recovered by the next evaluation,
        // and the agent still receives 202.
        try {
            $this->alerts->evaluate($result->server, $result->issues);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->respond(['status' => 'accepted'], 202);
    }

    /**
     * Checks the middleware already makes, repeated here so the controller's
     * behaviour does not depend on them: a body over the limit is a 413, a
     * non-JSON media type is a 415 (even for an empty body), and a
     * non-numeric or negative `Content-Length` is a 400. A malformed header
     * therefore never reaches validation and is never treated as valid
     * (R36.2).
     *
     * @return array{0: string, 1: int}|null
     */
    private function transportError(Request $request): ?array
    {
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null) {
            $trimmed = trim($contentLength);

            if (preg_match('/\A\d+\z/', $trimmed) !== 1) {
                return ['bad_request', 400];
            }
        }

        $content = $request->getContent();

        if (strlen($content) > (int) config('ingest.max_bytes')) {
            return ['payload_too_large', 413];
        }

        if (! $this->isJson($request->headers->get('Content-Type'))) {
            return ['unsupported_media_type', 415];
        }

        return null;
    }

    private function isJson(?string $contentType): bool
    {
        return $contentType !== null && preg_match(self::JSON_MEDIA_TYPE, $contentType) === 1;
    }

    /**
     * The body exactly as sent. Symfony replaces an empty string with `null`,
     * which would make a schema-invalid empty body look like malformed JSON;
     * normalise it back to `''`.
     */
    private function body(Request $request): string
    {
        return (string) $request->getContent();
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function error(string $error, int $status, array $errors = []): JsonResponse
    {
        $body = $errors === [] ? ['error' => $error] : ['errors' => $errors];

        return $this->respond($body, $status);
    }

    /**
     * Serialise a response body with a substitution fallback.
     *
     * The message bag quotes offending values, and PHP accepts invalid UTF-8
     * inside a JSON string, so echoing one back could make `json_encode()`
     * fail. Substitute the bad bytes and keep the structured error instead of
     * turning a rejected payload into a 500.
     *
     * @param  array<string, mixed>  $body
     */
    private function respond(array $body, int $status): JsonResponse
    {
        $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($payload)) {
            $payload = '{"error":"server_error"}';
            $status = 500;
        }

        return (new JsonResponse)
            ->setJson($payload)
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
