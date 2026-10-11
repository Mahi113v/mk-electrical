<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_spa_shell_is_served(): void
    {
        $this->get('/login')->assertOk();
    }
}
