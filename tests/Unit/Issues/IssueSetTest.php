<?php

namespace Tests\Unit\Issues;

use App\Enums\AlertRule;
use App\Enums\ServerHealth;
use App\Issues\IssueSet;
use PHPUnit\Framework\TestCase;

class IssueSetTest extends TestCase
{
    public function test_empty_reports_ok_with_no_codes_or_details(): void
    {
        $issues = IssueSet::empty();

        $this->assertSame(ServerHealth::Ok, $issues->health);
        $this->assertSame([], $issues->codes);
        $this->assertSame([], $issues->details);
    }

    public function test_has_matches_the_exact_code(): void
    {
        $issues = new IssueSet([AlertRule::DiskHigh->value], [
            AlertRule::DiskHigh->value => ['value' => 90.0, 'threshold' => 85, 'severity' => 'warning'],
        ]);

        $this->assertTrue($issues->has(AlertRule::DiskHigh->value));
        $this->assertFalse($issues->has(AlertRule::CpuHigh->value));
        // A prefix of a real code is not a match.
        $this->assertFalse($issues->has('disk'));
    }

    public function test_health_follows_the_highest_severity(): void
    {
        $warning = new IssueSet(['quota_over'], ['quota_over' => ['value' => 1, 'threshold' => 0, 'severity' => 'warning']]);
        $this->assertSame(ServerHealth::Warning, $warning->health);

        $critical = new IssueSet(['quota_over', 'disk_high'], [
            'quota_over' => ['value' => 1, 'threshold' => 0, 'severity' => 'warning'],
            'disk_high' => ['value' => 96.0, 'threshold' => 95, 'severity' => 'critical'],
        ]);
        $this->assertSame(ServerHealth::Critical, $critical->health);
    }
}
