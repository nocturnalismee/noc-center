<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path hands the visitor to the application shell.
     */
    public function test_the_application_redirects_the_root_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }
}
