<?php

namespace Tests\Unit\Enums;

use App\Enums\ServerStatus;
use Tests\TestCase;

class ServerStatusLabelTest extends TestCase
{
    public function test_labels_match_the_display_strings(): void
    {
        $this->assertSame('Never seen', ServerStatus::NeverSeen->label());
        $this->assertSame('Online', ServerStatus::Online->label());
        $this->assertSame('Stale', ServerStatus::Stale->label());
        $this->assertSame('Offline', ServerStatus::Offline->label());
    }
}
