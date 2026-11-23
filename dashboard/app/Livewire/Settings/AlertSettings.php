<?php

namespace App\Livewire\Settings;

use App\Alerts\AlertNotifier;
use App\Alerts\Channels\AlertErrorRedactor;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Alert thresholds and delivery channels (spec §7.9, §9.2, §9.4).
 *
 * Thresholds are written as overrides on top of `config/alerts.php` through
 * `SettingsRepository`, so `IssueDetector` picks them up without a deploy.
 * The Telegram bot token is write-only: only a boolean "Configured" state is
 * ever rendered, the stored value is ciphertext (R22), and the audit metadata
 * for a secret change carries the key and `is_secret` only.
 */
#[Layout('layouts.app')]
class AlertSettings extends Component
{
    private const BOT_TOKEN_KEY = 'alerts.channels.telegram.bot_token';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $rules = [];

    public string $reminderHours = '6';

    public bool $emailEnabled = true;

    public bool $telegramEnabled = false;

    public string $chatId = '';

    public string $messageThreadId = '';

    public string $botToken = '';

    public bool $replacingToken = false;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Setting::class);

        $settings = app(SettingsRepository::class);

        $this->reminderHours = (string) $settings->get('alerts.reminder_hours', config('alerts.reminder_hours'));
        $this->emailEnabled = (bool) $settings->get('alerts.channels.email.enabled', config('alerts.channels.email.enabled'));
        $this->telegramEnabled = (bool) $settings->get('alerts.channels.telegram.enabled', config('alerts.channels.telegram.enabled'));
        $this->chatId = (string) ($settings->get('alerts.channels.telegram.chat_id', config('alerts.channels.telegram.chat_id')) ?? '');
        $this->messageThreadId = (string) ($settings->get('alerts.channels.telegram.message_thread_id', config('alerts.channels.telegram.message_thread_id')) ?? '');

        foreach ((array) config('alerts.rules', []) as $rule => $keys) {
            foreach ($keys as $key => $default) {
                $value = $settings->get('alerts.rules.'.$rule.'.'.$key, $default);
                $this->rules[$rule][$key] = is_bool($value) ? $value : $value;
            }
        }
    }

    public function replaceToken(): void
    {
        $this->replacingToken = true;
        $this->botToken = '';
        $this->statusMessage = null;
        $this->resetErrorBag('botToken');
    }

    public function clearToken(): void
    {
        Gate::authorize('viewAny', Setting::class);

        app(SettingsRepository::class)->forget(self::BOT_TOKEN_KEY);

        app(AuditLogger::class)->log('settings.updated', null, [
            'key' => self::BOT_TOKEN_KEY,
            'is_secret' => true,
        ]);

        $this->replacingToken = false;
        $this->botToken = '';
        $this->statusMessage = 'Bot token cleared.';
    }

    public function save(): void
    {
        Gate::authorize('viewAny', Setting::class);

        $this->statusMessage = null;

        $this->validate($this->validationRules(), [
            'rules.*.warning' => 'Thresholds must be between 0 and 100.',
        ]);

        if ($this->hasCrossFieldError()) {
            return;
        }

        $settings = app(SettingsRepository::class);
        $audit = app(AuditLogger::class);
        $changed = [];

        $this->write($settings, 'alerts.reminder_hours', (int) $this->reminderHours, 'int', $changed);
        $this->write($settings, 'alerts.channels.email.enabled', $this->emailEnabled, 'bool', $changed);
        $this->write($settings, 'alerts.channels.telegram.enabled', $this->telegramEnabled, 'bool', $changed);
        $this->write($settings, 'alerts.channels.telegram.chat_id', trim($this->chatId), 'string', $changed);

        $thread = trim($this->messageThreadId);

        if ($thread === '') {
            $this->forgetIfPresent($settings, 'alerts.channels.telegram.message_thread_id', $changed);
        } else {
            $this->write($settings, 'alerts.channels.telegram.message_thread_id', (int) $thread, 'int', $changed);
        }

        foreach ((array) config('alerts.rules', []) as $rule => $keys) {
            foreach (array_keys($keys) as $key) {
                $value = $this->rules[$rule][$key] ?? null;

                if ($key === 'enabled') {
                    $this->write($settings, 'alerts.rules.'.$rule.'.'.$key, (bool) $value, 'bool', $changed);
                } elseif ($key === 'consecutive_samples' || str_ends_with($key, '_days')
                    || str_ends_with($key, '_hours') || str_ends_with($key, '_minutes')) {
                    $this->write($settings, 'alerts.rules.'.$rule.'.'.$key, (int) $value, 'int', $changed);
                } elseif (str_contains($key, 'multiplier')) {
                    $this->write($settings, 'alerts.rules.'.$rule.'.'.$key, (float) $value, 'float', $changed);
                } elseif (in_array($key, ['warning', 'critical'], true)) {
                    $this->write($settings, 'alerts.rules.'.$rule.'.'.$key, (int) $value, 'int', $changed);
                }
            }
        }

        if ($changed !== []) {
            $audit->log('settings.updated', null, [
                'keys' => $changed,
                'is_secret' => false,
            ]);
        }

        if (trim($this->botToken) !== '') {
            $settings->set(self::BOT_TOKEN_KEY, trim($this->botToken), 'string', true);

            $audit->log('settings.updated', null, [
                'key' => self::BOT_TOKEN_KEY,
                'is_secret' => true,
            ]);

            $this->botToken = '';
            $this->replacingToken = false;
        }

        $this->statusMessage = 'Alert settings saved.';
    }

    public function sendTest(string $channel): void
    {
        Gate::authorize('viewAny', Setting::class);

        $this->statusMessage = null;
        $this->resetErrorBag('channel');

        if (! in_array($channel, ['email', 'telegram'], true)) {
            $this->addError('channel', 'Unknown alert channel.');

            return;
        }

        $key = 'alert-test:'.auth()->id();
        $max = max(1, (int) config('alerts.test_rate_per_minute', 3));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError('channel', 'Too many test messages. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            app(AlertNotifier::class)->sendTest($channel, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        } catch (Throwable $exception) {
            $this->addError('channel', AlertErrorRedactor::fromThrowable($exception));

            return;
        }

        $this->statusMessage = ucfirst($channel).' test message sent.';
    }

    public function tokenConfigured(): bool
    {
        return app(SettingsRepository::class)->hasSecret(self::BOT_TOKEN_KEY);
    }

    /**
     * @return array{default: string, host: string, port: string, encryption: string, username: string, from: string, configured: bool}
     */
    public function smtpStatus(): array
    {
        $host = (string) config('mail.mailers.smtp.host', '');
        $from = (string) config('mail.from.address', '');

        return [
            'default' => (string) config('mail.default', ''),
            'host' => $host,
            'port' => (string) config('mail.mailers.smtp.port', ''),
            'encryption' => (string) (config('mail.mailers.smtp.encryption') ?? 'none'),
            'username' => (string) (config('mail.mailers.smtp.username') ?? ''),
            'from' => $from,
            'configured' => $host !== '' && $from !== '',
        ];
    }

    public function render(): View
    {
        return view('livewire.settings.alert-settings', [
            'ruleConfig' => (array) config('alerts.rules', []),
            'smtp' => $this->smtpStatus(),
            'tokenConfigured' => $this->tokenConfigured(),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function validationRules(): array
    {
        $rules = [
            'reminderHours' => ['required', 'integer', 'min:1', 'max:720'],
            'emailEnabled' => ['boolean'],
            'telegramEnabled' => ['boolean'],
            'chatId' => trim($this->chatId) === ''
                ? [Rule::requiredIf(fn (): bool => $this->telegramEnabled), 'nullable']
                : ['required', 'string', 'max:64', 'regex:/^(@[A-Za-z][A-Za-z0-9_]{4,63}|-?[0-9]{1,20})$/'],
            'messageThreadId' => trim($this->messageThreadId) === ''
                ? ['nullable']
                : ['required', 'integer', 'min:1'],
            'botToken' => trim($this->botToken) === ''
                ? [Rule::requiredIf(fn (): bool => $this->replacingToken), 'nullable']
                : ['required', 'string', 'max:200', 'regex:/^[0-9]{5,20}:[A-Za-z0-9_-]{20,64}$/'],
        ];

        foreach ((array) config('alerts.rules', []) as $rule => $keys) {
            foreach (array_keys($keys) as $key) {
                $path = 'rules.'.$rule.'.'.$key;

                $rules[$path] = match (true) {
                    $key === 'enabled' => ['boolean'],
                    $key === 'consecutive_samples' => ['required', 'integer', 'min:2', 'max:10'],
                    str_ends_with($key, '_days') => ['required', 'integer', 'min:1', 'max:3650'],
                    str_ends_with($key, '_hours') => ['required', 'integer', 'min:1', 'max:8760'],
                    str_ends_with($key, '_minutes') => ['required', 'integer', 'min:1', 'max:525600'],
                    str_contains($key, 'multiplier') => ['required', 'numeric', 'min:0.1', 'max:100'],
                    default => ['required', 'integer', 'min:0', 'max:100'],
                };
            }
        }

        return $rules;
    }

    private function hasCrossFieldError(): bool
    {
        $valid = true;

        foreach ((array) config('alerts.rules', []) as $rule => $keys) {
            if (! array_key_exists('warning', $keys) || ! array_key_exists('critical', $keys)) {
                continue;
            }

            $warning = (float) ($this->rules[$rule]['warning'] ?? 0);
            $critical = (float) ($this->rules[$rule]['critical'] ?? 0);

            if ($warning > $critical) {
                $this->addError('rules.'.$rule.'.warning', 'Warning must not exceed the critical threshold.');
                $valid = false;
            }
        }

        if ($this->telegramEnabled
            && ! $this->tokenConfigured()
            && trim($this->botToken) === '') {
            $this->addError('botToken', 'A bot token is required when Telegram is enabled.');
            $valid = false;
        }

        return ! $valid;
    }

    /**
     * @param  list<string>  $changed
     */
    private function write(SettingsRepository $settings, string $key, mixed $value, string $type, array &$changed): void
    {
        $current = $settings->get($key, config($key));

        if ($this->differs($current, $value)) {
            $changed[] = $key;
        }

        $settings->set($key, $value, $type);
    }

    /**
     * @param  list<string>  $changed
     */
    private function forgetIfPresent(SettingsRepository $settings, string $key, array &$changed): void
    {
        if ($settings->get($key, config($key)) !== null) {
            $settings->forget($key);
            $changed[] = $key;
        }
    }

    private function differs(mixed $current, mixed $value): bool
    {
        if (is_bool($current) || is_bool($value)) {
            return (bool) $current !== (bool) $value;
        }

        if (is_numeric($current) && is_numeric($value)) {
            return (float) $current !== (float) $value;
        }

        return (string) $current !== (string) $value;
    }
}
