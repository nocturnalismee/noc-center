@extends('layouts.app')

@section('title', 'Agent guide · NOC Center')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
    <span class="px-2 text-border-strong">/</span>
    <span class="text-text">Agent guide</span>
@endsection

@section('content')
    <div class="mx-auto w-full max-w-[900px]">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-[26px] font-semibold leading-8 tracking-[-0.01em] text-text">Agent guide</h1>
                <p class="mt-1.5 max-w-[72ch] text-[13px] text-text-sub">
                    Install the Bash collector on a monitored Linux server. It runs from systemd timers, needs no daemon, and sends one signed sample per interval.
                </p>
            </div>
            <a href="{{ route('servers.create') }}" class="primary-button">Add server</a>
        </div>

        <div class="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_260px]">
            <div class="min-w-0 space-y-8">
                <section aria-labelledby="requirements-heading">
                    <h2 id="requirements-heading" class="text-[15px] font-semibold text-text">Requirements</h2>
                    <ul class="mt-3 space-y-2 border-t border-divider pt-3 text-[13px] text-text-sub">
                        <li>Linux with systemd and Bash 4 or newer.</li>
                        <li>Root access to install the agent and read system metrics.</li>
                        <li><code class="font-mono text-[12px]">curl</code>, <code class="font-mono text-[12px]">flock</code>, and standard coreutils.</li>
                        <li>Outbound HTTPS to this dashboard. No inbound port or SSH access is required.</li>
                        <li>Optional module tools: <code class="font-mono text-[12px]">smartctl</code>, <code class="font-mono text-[12px]">repquota</code>, a MySQL defaults file, and <code class="font-mono text-[12px]">imunify360-agent</code>.</li>
                    </ul>
                </section>

                <section aria-labelledby="install-heading">
                    <h2 id="install-heading" class="text-[15px] font-semibold text-text">Install</h2>
                    <p class="mt-1 text-[13px] text-text-sub">
                        Create the server in the dashboard and copy its one-time token, then run these commands as root on the target server. Replace the example dashboard URL with the public URL of this installation.
                    </p>

                    <ol class="mt-5 space-y-5">
                        <li>
                            <p class="text-[13px] font-medium text-text">1. Store the token in a root-only file</p>
                            <pre class="mt-2 overflow-x-auto border border-border bg-surface px-4 py-3 font-mono text-[12px] leading-5 text-text">sudo install -m 600 /dev/null /root/noc-token
sudo bash -c 'read -rsp "Token: " token; printf "%s" "$token" &gt; /root/noc-token; echo'</pre>
                            <p class="mt-1.5 text-[12px] text-text-muted">Paste the token at the prompt. The installer never receives it as a command-line argument.</p>
                        </li>

                        <li>
                            <p class="text-[13px] font-medium text-text">2. Download and install the agent</p>
                            <pre class="mt-2 overflow-x-auto border border-border bg-surface px-4 py-3 font-mono text-[12px] leading-5 text-text">sudo curl -fsSL https://noc.example.com/agent/download -o /root/noc-agent.tar.gz
sudo rm -rf /root/noc-agent
sudo tar -xzpf /root/noc-agent.tar.gz -C /root
sudo /root/noc-agent/install.sh \
  --api-url https://noc.example.com/api/v1/ingest \
  --token-file /root/noc-token</pre>
                            <p class="mt-1.5 text-[12px] text-text-muted">The installer is idempotent. Add <code class="font-mono">--interval 120</code> for a two-minute collection interval.</p>
                        </li>

                        <li>
                            <p class="text-[13px] font-medium text-text">3. Wait for the first sample</p>
                            <p class="mt-1 text-[13px] text-text-sub">
                                The first sample normally arrives within one interval. Open the server page and use <span class="font-medium text-text">Waiting for first check-in</span> to watch for it.
                            </p>
                        </li>
                    </ol>
                </section>

                <section aria-labelledby="verify-heading">
                    <h2 id="verify-heading" class="text-[15px] font-semibold text-text">Verify the installation</h2>
                    <pre class="mt-3 overflow-x-auto border border-border bg-surface px-4 py-3 font-mono text-[12px] leading-5 text-text">systemctl list-timers 'noc-agent*'
systemctl status noc-agent.timer
journalctl -u noc-agent.service -n 50 --no-pager</pre>
                    <p class="mt-2 text-[12px] text-text-muted">
                        The agent writes module caches under <code class="font-mono">/var/lib/noc-agent/</code>. A failed SMART, quota, or Imunify module does not stop the main collection timer.
                    </p>
                </section>

                <section aria-labelledby="troubleshooting-heading">
                    <h2 id="troubleshooting-heading" class="text-[15px] font-semibold text-text">Troubleshooting</h2>
                    <dl class="mt-3 border-t border-divider text-[13px]">
                        <div class="border-b border-divider py-3">
                            <dt class="font-medium text-text">No sample after several minutes</dt>
                            <dd class="mt-1 text-text-sub">Check that the dashboard URL is reachable from the server, the token prefix matches the server page, and the system clock is correct.</dd>
                        </div>
                        <div class="border-b border-divider py-3">
                            <dt class="font-medium text-text">HTTP 401 from ingest</dt>
                            <dd class="mt-1 text-text-sub">The token is unknown, revoked, or expired. Rotate it from the server token page and install the new token file.</dd>
                        </div>
                        <div class="border-b border-divider py-3">
                            <dt class="font-medium text-text">HTTP 403 from ingest</dt>
                            <dd class="mt-1 text-text-sub">The token is valid but the server is inactive. Re-enable the server or register a new one.</dd>
                        </div>
                        <div class="py-3">
                            <dt class="font-medium text-text">Panel or module data is missing</dt>
                            <dd class="mt-1 text-text-sub">The module tool is not installed or its cache has not been primed yet. The main system metrics continue to arrive.</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside class="space-y-6 text-[12px] leading-5 text-text-sub">
                <section class="border-t border-border pt-4">
                    <h2 class="text-[13px] font-semibold text-text">Token handling</h2>
                    <p class="mt-2">Tokens are shown once and stored as an HMAC hash. Rotating a token keeps the previous one valid for 24 hours; revoking is immediate.</p>
                    <a href="{{ route('agent.download') }}" class="mt-3 inline-block text-focus hover:underline">Download noc-agent.tar.gz &rarr;</a>
                </section>

                <section class="border-t border-border pt-4">
                    <h2 class="text-[13px] font-semibold text-text">Local testing</h2>
                    <p class="mt-2">For a local dashboard, the installer accepts <code class="font-mono">http://127.0.0.1</code> and <code class="font-mono">http://localhost</code>. Production installs require HTTPS.</p>
                </section>

                <section class="border-t border-border pt-4">
                    <h2 class="text-[13px] font-semibold text-text">Need a server first?</h2>
                    <p class="mt-2">Register the server to get its token and a ready-to-copy install command.</p>
                    <a href="{{ route('servers.create') }}" class="mt-3 inline-block text-focus hover:underline">Add server &rarr;</a>
                </section>
            </aside>
        </div>
    </div>
@endsection
