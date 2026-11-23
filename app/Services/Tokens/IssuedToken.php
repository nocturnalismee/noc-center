<?php

namespace App\Services\Tokens;

use App\Models\ServerToken;

/**
 * A freshly minted agent token whose plaintext exists only in memory.
 *
 * The plaintext is revealed once to the operator and is never persisted.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $plain,
        public ServerToken $token,
    ) {
        //
    }

    /**
     * Never expose the plaintext through debug output (`dd`, `var_dump`).
     *
     * @return array{prefix: string, plaintext: string, token: ServerToken}
     */
    public function __debugInfo(): array
    {
        return [
            'prefix' => substr($this->plain, 0, 8),
            'plaintext' => '[redacted]',
            'token' => $this->token,
        ];
    }
}
