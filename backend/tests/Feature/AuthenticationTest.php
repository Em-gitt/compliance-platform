<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sanctum only starts a session for requests that originate from a
    // configured stateful SPA domain. This matches the browser login flow.
    $this->withHeader('Referer', 'http://localhost');
    $this->seed(CatalogSeeder::class);
    $this->organization = Organization::query()->create([
        'legal_name' => 'Authentication Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);
});

function authenticationUser(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'organization_id' => test()->organization->id,
        'name' => 'Authentication User',
        'email' => 'auth@example.test',
        'password' => 'secret-password',
        'role' => 'administrator',
        'is_active' => true,
    ], $attributes));
}

it('returns the exact validation messages', function (): void {
    $this->postJson('/api/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'email' => 'Email is required.',
            'password' => 'Password is required.',
        ]);

    $this->postJson('/api/auth/login', ['email' => 'not-an-email', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Enter a valid email address.']);
});

it('rejects wrong credentials with the exact message', function (): void {
    authenticationUser();

    $this->postJson('/api/auth/login', ['email' => 'auth@example.test', 'password' => 'incorrect'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'The email or password is incorrect.']);
});

it('rejects inactive users with the exact message', function (): void {
    authenticationUser(['is_active' => false]);

    $this->postJson('/api/auth/login', ['email' => 'auth@example.test', 'password' => 'secret-password'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Your account is inactive. Contact your administrator.']);
});

it('logs in an active user with an argon2id hash and records the security audit event', function (): void {
    $user = authenticationUser();

    expect($user->password)->toStartWith('$argon2id$');
    expect(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->postJson('/api/auth/login', ['email' => 'auth@example.test', 'password' => 'secret-password'])
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => 'Authentication User',
            'role' => 'administrator',
            'organization_id' => $this->organization->id,
        ]);

    expect($user->fresh()->last_login_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'category' => 'security',
        'action' => 'login',
        'ip_address' => '127.0.0.1',
    ]);
    expect(AuditLog::query()->where('user_id', $user->id)->where('action', 'login')->exists())->toBeTrue();
});

it('returns the authenticated user and logs out', function (): void {
    $user = authenticationUser();

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => 'Authentication User',
            'role' => 'administrator',
            'organization_id' => $this->organization->id,
        ]);

    $this->actingAs($user)
        ->postJson('/api/auth/logout')
        ->assertNoContent();
});

it('returns the exact unauthenticated message for protected endpoints', function (): void {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->postJson('/api/documents')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('keeps health public and rate limits login attempts with the exact message', function (): void {
    Http::fake([
        '*' => Http::response(['status' => 'ok'], 200),
    ]);

    $this->getJson('/api/health')->assertOk();

    // Use an isolated client identity so attempts made by preceding feature
    // tests cannot consume this test's ten-request throttle allowance.
    $ipAddress = '203.0.113.'.random_int(1, 254);

    $this->withHeaders([
        'X-Forwarded-For' => $ipAddress,
        'X-Forwarded-Proto' => 'https',
    ]);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'password'])
            ->assertUnauthorized();
    }

    $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('message', fn (string $message): bool => (bool) preg_match('/^Too many sign-in attempts\\. Try again in \\d+ seconds\\.$/', $message));
});

it('strictly ignores AUTH_RATE_LIMIT in production environment', function (): void {
    config(['auth.login_rate_limit' => 100]);

    app()->detectEnvironment(fn () => 'production');

    $request = Request::create('/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
    $limiter = RateLimiter::limiter('login');
    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(10);

    // In testing/local environment, it respects the configured limit
    app()->detectEnvironment(fn () => 'testing');
    $limitTesting = $limiter($request);
    expect($limitTesting->maxAttempts)->toBe(100);

    config(['auth.login_rate_limit' => 10]);
});
