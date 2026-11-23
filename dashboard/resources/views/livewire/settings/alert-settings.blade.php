<div class="mx-auto w-full max-w-[1100px]">
    @section('title', 'Alert settings · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('settings.index') }}" class="hover:text-text">Settings</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Alert settings</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Alert settings</h1>
        <p class="mt-1.5 max-w-[78ch] text-[11.5px] text-text-sub">
            Thresholds, reminders, and delivery channels. In-app alerts are always on. Threshold changes apply at the next evaluation.
        </p>
    </div>

    @include('livewire.settings.nav')

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    @error('channel')
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-critical" role="alert">{{ $message }}</p>
    @enderror

    <form wire:submit="save" class="mt-8 flex flex-col gap-10">
        <section class="border-t border-border pt-5" aria-labelledby="thresholds-heading">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <h2 id="thresholds-heading" class="text-[13px] font-semibold text-text">Rules and thresholds</h2>
                <p class="text-[9px] uppercase tracking-[0.08em] text-text-muted">config/alerts.php defaults</p>
            </div>

            <div class="mt-4 border-t border-divider">
                @foreach ($ruleConfig as $rule => $keys)
                    <div class="border-b border-divider py-4" wire:key="rule-{{ $rule }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[12px] font-medium text-text">{{ Str::headline($rule) }}</p>
                                <p class="mt-0.5 text-[9.5px] text-text-muted">
                                    @switch($rule)
                                        @case('server_offline') Critical when the server passes its offline deadline. @break
                                        @case('server_stale') Warning when no sample arrives before the stale deadline. @break
                                        @case('cpu_high') CPU percentage across consecutive samples. @break
                                        @case('memory_high') Memory percentage. @break
                                        @case('disk_high') Root disk percentage. @break
                                        @case('inode_high') Root inode percentage. @break
                                        @case('load_high') Load average relative to CPU cores. @break
                                        @case('smart_failed') SMART reports a failed device. @break
                                        @case('smart_warning') SMART warning or a pending/reallocated sector. @break
                                        @case('service_down') Installed and enabled service is not running. @break
                                        @case('mysql_unreachable') MySQL is reported unavailable. @break
                                        @case('quota_over') Panel disk quota is exceeded. @break
                                        @case('module_cache_stale') Collector module data is older than the configured window. @break
                                        @case('security_disabled') CSF or ModSecurity is explicitly disabled (opt-in). @break
                                        @default Rule from the alert engine.
                                    @endswitch
                                </p>
                            </div>

                            <label for="rule-enabled-{{ $rule }}" class="inline-flex min-h-9 cursor-pointer items-center gap-2 text-[10.5px] text-text-sub">
                                <input
                                    id="rule-enabled-{{ $rule }}"
                                    type="checkbox"
                                    wire:model="rules.{{ $rule }}.enabled"
                                    class="h-4 w-4 rounded border-border bg-surface-2"
                                >
                                Enabled
                            </label>
                        </div>

                        @php
                            $inputs = collect($keys)->except('enabled');
                        @endphp

                        @if ($inputs->isNotEmpty())
                            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($inputs as $key => $default)
                                    @php
                                        $step = str_contains($key, 'multiplier') ? '0.1' : '1';
                                        $label = match ($key) {
                                            'warning' => 'Warning',
                                            'critical' => 'Critical',
                                            'warning_multiplier' => 'Warning × cores',
                                            'critical_multiplier' => 'Critical × cores',
                                            'consecutive_samples' => 'Consecutive samples',
                                            'smart_days' => 'SMART cache (days)',
                                            'diskquota_hours' => 'Quota cache (hours)',
                                            'imunify_minutes' => 'Imunify cache (minutes)',
                                            'mysql_minutes' => 'MySQL cache (minutes)',
                                            default => Str::headline($key),
                                        };
                                    @endphp

                                    <div>
                                        <label for="rule-{{ $rule }}-{{ $key }}" class="field-label">{{ $label }}</label>
                                        <input
                                            id="rule-{{ $rule }}-{{ $key }}"
                                            type="number"
                                            step="{{ $step }}"
                                            wire:model="rules.{{ $rule }}.{{ $key }}"
                                            class="field font-mono"
                                            @error('rules.'.$rule.'.'.$key) aria-invalid="true" @enderror
                                        >
                                        @error('rules.'.$rule.'.'.$key)
                                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-5 max-w-xs">
                <label for="reminder-hours" class="field-label">Reminder hours</label>
                <input
                    id="reminder-hours"
                    type="number"
                    min="1"
                    max="720"
                    wire:model="reminderHours"
                    class="field font-mono"
                    @error('reminderHours') aria-invalid="true" @enderror
                >
                <p class="mt-1.5 text-[9px] text-text-muted">Open alerts are reminded at most once per window. Acknowledged alerts are not reminded.</p>
                @error('reminderHours')
                    <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="border-t border-border pt-5" aria-labelledby="email-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="email-heading" class="text-[13px] font-semibold text-text">Email via SMTP</h2>
                    <p class="mt-1 text-[10px] text-text-sub">Configured through MAIL_* environment variables. The password is never shown.</p>
                </div>

                <label for="email-enabled" class="inline-flex min-h-9 cursor-pointer items-center gap-2 text-[10.5px] text-text-sub">
                    <input id="email-enabled" type="checkbox" wire:model="emailEnabled" class="h-4 w-4 rounded border-border bg-surface-2">
                    Send email alerts
                </label>
            </div>

            <dl class="mt-4 grid gap-x-8 gap-y-3 border-t border-divider pt-4 text-[10.5px] sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">Transport</dt>
                    <dd class="mt-1 font-mono text-text">{{ $smtp['default'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">Host</dt>
                    <dd class="mt-1 font-mono text-text">{{ $smtp['host'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">Port / encryption</dt>
                    <dd class="mt-1 font-mono text-text">{{ $smtp['port'] ?: '—' }} · {{ $smtp['encryption'] }}</dd>
                </div>
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">From</dt>
                    <dd class="mt-1 truncate font-mono text-text">{{ $smtp['from'] ?: '—' }}</dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <p class="text-[9.5px] text-text-sub">
                    {{ $smtp['configured'] ? 'Configured' : 'Not configured' }}
                    @if ($smtp['username'] !== '')
                        · user {{ $smtp['username'] }}
                    @endif
                </p>

                <button type="button" wire:click="sendTest('email')" class="secondary-button h-9">Send test email</button>
            </div>
        </section>

        <section class="border-t border-border pt-5" aria-labelledby="telegram-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="telegram-heading" class="text-[13px] font-semibold text-text">Telegram</h2>
                    <p class="mt-1 max-w-[70ch] text-[10px] text-text-sub">
                        One global target: a group/channel ID such as -100… or @channelname, plus an optional forum topic ID.
                    </p>
                </div>

                <label for="telegram-enabled" class="inline-flex min-h-9 cursor-pointer items-center gap-2 text-[10.5px] text-text-sub">
                    <input id="telegram-enabled" type="checkbox" wire:model="telegramEnabled" class="h-4 w-4 rounded border-border bg-surface-2">
                    Send Telegram alerts
                </label>
            </div>

            <div class="mt-4 grid gap-5 border-t border-divider pt-4 sm:grid-cols-2">
                <div>
                    <label for="telegram-chat-id" class="field-label">Chat ID</label>
                    <input
                        id="telegram-chat-id"
                        type="text"
                        wire:model="chatId"
                        class="field font-mono"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="-1001234567890 or @channelname"
                        @error('chatId') aria-invalid="true" @enderror
                    >
                    <p class="mt-1.5 text-[9px] text-text-muted">Group IDs may be negative; channel usernames start with @.</p>
                    @error('chatId')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="telegram-thread-id" class="field-label">Message thread ID</label>
                    <input
                        id="telegram-thread-id"
                        type="number"
                        min="1"
                        wire:model="messageThreadId"
                        class="field font-mono"
                        placeholder="Optional"
                        @error('messageThreadId') aria-invalid="true" @enderror
                    >
                    <p class="mt-1.5 text-[9px] text-text-muted">Positive integer for a forum topic. Leave blank to omit it from the payload.</p>
                    @error('messageThreadId')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <span class="field-label">Bot token</span>

                    @if ($tokenConfigured && ! $replacingToken)
                        <div class="mt-1 flex flex-wrap items-center gap-3">
                            <p class="text-[11px] text-text">Configured</p>
                            <button type="button" wire:click="replaceToken" class="secondary-button h-9">Replace</button>
                            <button type="button" wire:click="clearToken" wire:confirm="Clear the Telegram bot token?" class="secondary-button h-9">Clear</button>
                        </div>
                        <p class="mt-1.5 text-[9px] text-text-muted">The token is stored encrypted and is never shown again.</p>
                    @else
                        <input
                            id="telegram-bot-token"
                            type="password"
                            wire:model="botToken"
                            class="field mt-1 font-mono"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="123456789:AA…"
                            @error('botToken') aria-invalid="true" @enderror
                        >
                        <p class="mt-1.5 text-[9px] text-text-muted">Write-only. Saving replaces the stored token.</p>
                        @error('botToken')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    @endif
                </div>
            </div>

            <div class="mt-4">
                <button type="button" wire:click="sendTest('telegram')" class="secondary-button h-9">Send test message</button>
            </div>
        </section>

        <div class="border-t border-border pt-5">
            <button type="submit" class="primary-button">Save alert settings</button>
        </div>
    </form>
</div>
