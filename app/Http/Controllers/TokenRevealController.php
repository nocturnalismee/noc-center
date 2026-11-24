<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Tokens\TokenService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TokenRevealController extends Controller
{
    /**
     * The one-time token pattern minted by {@see TokenService}.
     */
    private const TOKEN_PATTERN = '/\Anoc_[A-Za-z0-9_-]{43}\z/';

    /**
     * Show the freshly minted token exactly once.
     *
     * The plaintext lives in a single-request flash payload. Pulling it means
     * a refresh, a back navigation, or a second tab gets a 404 instead of the
     * token. Anything missing, malformed, or issued for another server is also
     * a 404, never a partial page.
     */
    public function show(Request $request, Server $server): Response
    {
        Gate::authorize('manageTokens', Server::class);

        $payload = $request->session()->get('issued_token');

        abort_unless(is_array($payload), 404);
        abort_unless(($payload['server_id'] ?? null) === $server->getKey(), 404);
        abort_unless(is_string($payload['token_cipher'] ?? null), 404);
        abort_unless(is_string($payload['prefix'] ?? null), 404);

        $request->session()->forget('issued_token');

        try {
            $plain = Crypt::decryptString($payload['token_cipher']);
        } catch (DecryptException) {
            abort(404);
        }

        abort_unless(preg_match(self::TOKEN_PATTERN, $plain) === 1, 404);
        abort_unless($payload['prefix'] === substr($plain, 0, 8), 404);

        $token = $server->tokens()->latest('id')->first();

        $createdAt = $token?->created_at !== null
            ? $token->created_at->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB'
            : 'Just now';

        $baseUrl = rtrim((string) config('app.url'), '/');

        // URL schemes are case-insensitive, but the agent's strict validator
        // compares them literally. Normalise APP_URL so a pasted uppercase
        // scheme cannot produce an install command the agent rejects.
        $baseUrl = (string) preg_replace_callback(
            '/^[A-Za-z][A-Za-z0-9+.-]*:\/\//',
            static fn (array $match): string => strtolower($match[0]),
            $baseUrl,
        );

        $installCommand = "sudo install -m 600 /dev/null /root/noc-token\n"
            ."sudo bash -c 'read -rsp \"Token: \" token; printf \"%s\" \"\$token\" > /root/noc-token; echo'\n"
            ."# paste the token at the prompt, then press Enter\n"
            ."sudo curl -fsSL {$baseUrl}/agent/download -o /root/noc-agent.tar.gz\n"
            ."sudo rm -rf /root/noc-agent\n"
            ."sudo tar -xzpf /root/noc-agent.tar.gz -C /root\n"
            ."sudo /root/noc-agent/install.sh \\\n"
            ."  --api-url {$baseUrl}/api/v1/ingest \\\n"
            .'  --token-file /root/noc-token';

        return response()
            ->view('servers.token-reveal', [
                'server' => $server,
                'plain' => $plain,
                'prefix' => $payload['prefix'],
                'installCommand' => $installCommand,
                'createdAt' => $createdAt,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
