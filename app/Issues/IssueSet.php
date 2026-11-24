<?php

namespace App\Issues;

use App\Enums\AlertRule;
use App\Enums\ServerHealth;

/**
 * The issue state of one server for one sample.
 *
 * `codes` is ordered by detection order and holds `AlertRule` values only
 * (liveness rules such as `server_offline` belong to the scheduler).
 * Every detail carries the `severity` that was used to trigger it, so the
 * alert engine never re-derives a threshold: changing a threshold
 * between detection and alert evaluation cannot silently promote a warning
 * to a critical or the other way around (R24).
 *
 * @phpstan-type IssueDetail array{value: mixed, threshold: mixed, severity: string}
 */
final readonly class IssueSet
{
    public ServerHealth $health;

    /**
     * @param  list<string>  $codes  {@see AlertRule} values
     * @param  array<string, IssueDetail>  $details  keyed by issue code
     */
    public function __construct(
        public array $codes,
        public array $details,
    ) {
        $this->health = self::health($details);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes, true);
    }

    /**
     * Spec §9.1/§10: critical when any issue is critical, warning when any is
     * a warning, otherwise ok.
     *
     * @param  array<string, IssueDetail>  $details
     */
    private static function health(array $details): ServerHealth
    {
        $severities = array_column($details, 'severity');

        if (in_array('critical', $severities, true)) {
            return ServerHealth::Critical;
        }

        if (in_array('warning', $severities, true)) {
            return ServerHealth::Warning;
        }

        return ServerHealth::Ok;
    }
}
