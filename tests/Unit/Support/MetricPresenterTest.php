<?php

namespace Tests\Unit\Support;

use App\Support\MetricPresenter;
use Tests\TestCase;

class MetricPresenterTest extends TestCase
{
    /**
     * The quota table keeps the old `number()` fallback for non-numeric
     * scalar values: the formatted string is stored at collection and shown
     * verbatim, so text such as "1.5 GB" survives instead of losing its unit.
     */
    public function test_quota_used_gb_keeps_the_text_fallback_for_non_numeric_values(): void
    {
        $group = MetricPresenter::groups([
            'diskquota.top.alice.used_gb' => '1.5 GB',
        ]);

        $this->assertSame([
            ['user' => 'alice', 'used_gb' => '1.5 GB'],
        ], $group['quota']['top']);
    }

    public function test_quota_used_gb_formats_numeric_values(): void
    {
        $group = MetricPresenter::groups([
            'diskquota.top.bob.used_gb' => 12.5,
        ]);

        $this->assertSame([
            ['user' => 'bob', 'used_gb' => '12.5'],
        ], $group['quota']['top']);
    }

    public function test_quota_top_is_sorted_by_numeric_value_descending(): void
    {
        $group = MetricPresenter::groups([
            'diskquota.top.small.used_gb' => 2,
            'diskquota.top.large.used_gb' => 30,
            'diskquota.top.middle.used_gb' => 12.5,
        ]);

        $this->assertSame(['large', 'middle', 'small'], array_column($group['quota']['top'], 'user'));
    }
}
