<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_defensive_headers_are_present_on_responses(): void
    {
        $res = $this->getJson('/api/jobs'); // public endpoint

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('X-XSS-Protection', '0');
    }
}
