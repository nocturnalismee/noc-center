<?php

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TypographyScaleTest extends TestCase
{
    /**
     * The approved global type ramp. Adding a new size means updating the
     * design system, not sprinkling another arbitrary pixel value into a view.
     *
     * @var list<string>
     */
    private const SCALE = [
        '11', '12', '13', '13.5', '15', '24', '26', '28',
    ];

    public function test_blade_views_only_use_the_approved_type_scale(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/text-\[([0-9.]+)px\]/', $file->getContents(), $matches);

            foreach ($matches[1] as $size) {
                if (! in_array($size, self::SCALE, true)) {
                    $offenders[] = $file->getRelativePathname().' → '.$size.'px';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'Unapproved type sizes found.');
    }

    public function test_the_stylesheet_has_no_type_below_the_readable_floor(): void
    {
        $css = File::get(resource_path('css/app.css'));

        preg_match_all('/font-size:\s*([0-9.]+)px/', $css, $matches);

        $this->assertNotSame([], $matches[1], 'No font sizes found in the stylesheet.');

        foreach ($matches[1] as $size) {
            $this->assertGreaterThanOrEqual(11.0, (float) $size, 'A stylesheet type size is below 11px.');
        }

        $this->assertMatchesRegularExpression('/body\s*\{[^}]*font-size:\s*14px/s', $css);
    }

    public function test_chart_ticks_follow_the_same_scale(): void
    {
        $js = File::get(resource_path('js/charts.js'));

        preg_match_all('/font:\s*\{\s*size:\s*([0-9.]+)/', $js, $matches);

        $this->assertNotSame([], $matches[1], 'No chart font sizes found.');

        foreach ($matches[1] as $size) {
            $this->assertGreaterThanOrEqual(11.0, (float) $size, 'A chart tick size is below 11px.');
        }
    }
}
