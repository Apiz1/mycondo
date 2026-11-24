<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiBasicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_gets_json_401_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}