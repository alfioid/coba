<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

class AuthTest extends TestCase
{
    /**
     * TC-01: Login with valid credentials.
     */
    public function test_login_valid(): void
    {
        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'Password123',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
    }

    /**
     * TC-02: Login with invalid password.
     */
    public function test_login_invalid_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'WrongPass',
        ]);
        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Invalid credentials']);
    }

    /**
     * TC-09: Access protected API without token.
     */
    public function test_api_without_token(): void
    {
        $response = $this->getJson('/api/items');
        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Authentication required']);
    }

    /**
     * TC-13: Access admin API with expired JWT.
     */
    public function test_admin_api_expired_token(): void
    {
        $expiredToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.' .
                         'expired.payload.' .
                         'signature'; // placeholder token
        $response = $this->withHeaders([
            'Authorization' => "Bearer $expiredToken",
        ])->getJson('/api/admin');
        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Token expired']);
    }
}
