<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Lockout Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Lockout User',
        'email' => 'lockout@example.test',
        'password' => 'CorrectPassword123!',
        'role' => 'administrator',
        'is_active' => true,
    ]);
});

it('locks account after 5 consecutive failed logins with exact message and time', function (): void {
    $testNow = Carbon::parse('2026-11-19 10:00:00', 'UTC');
    Carbon::setTestNow($testNow);

    for ($i = 1; $i <= 4; $i++) {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'lockout@example.test',
            'password' => 'WrongPassword',
        ]);
        $response->assertStatus(401)
            ->assertJson(['message' => 'The email or password is incorrect.']);

        expect($this->user->fresh()->failed_login_attempts)->toBe($i);
    }

    $lockTimeExpected = $testNow->copy()->addMinutes(15)->timezone('America/Mexico_City')->format('H:i');

    $response5 = $this->postJson('/api/auth/login', [
        'email' => 'lockout@example.test',
        'password' => 'WrongPassword',
    ]);

    $response5->assertStatus(423)
        ->assertJson([
            'message' => "Your account has been locked for 15 minutes due to too many failed sign-in attempts. Try again at {$lockTimeExpected}.",
        ]);

    $freshUser = $this->user->fresh();
    expect($freshUser->failed_login_attempts)->toBe(5);
    expect($freshUser->locked_until)->not->toBeNull();

    // Verify auth.account_locked audit record exists
    $audit = AuditLog::where('action', 'auth.account_locked')->where('user_id', $this->user->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->category)->toBe('security');
    expect($audit->resource_type)->toBe('user');
});

it('rejects login during lock even with correct password and shows remaining minutes', function (): void {
    $testNow = Carbon::parse('2026-11-19 10:00:00', 'UTC');
    Carbon::setTestNow($testNow);

    // Lock the user
    $this->user->update([
        'failed_login_attempts' => 5,
        'locked_until' => $testNow->copy()->addMinutes(15),
    ]);

    // Test attempt at now (15 minutes remaining)
    $response = $this->postJson('/api/auth/login', [
        'email' => 'lockout@example.test',
        'password' => 'CorrectPassword123!',
    ]);

    $response->assertStatus(423)
        ->assertJson([
            'message' => 'Your account is locked. Try again in 15 minutes.',
        ]);

    // Fast-forward 5 minutes (10 minutes remaining)
    Carbon::setTestNow($testNow->copy()->addMinutes(5));

    $response2 = $this->postJson('/api/auth/login', [
        'email' => 'lockout@example.test',
        'password' => 'CorrectPassword123!',
    ]);

    $response2->assertStatus(423)
        ->assertJson([
            'message' => 'Your account is locked. Try again in 10 minutes.',
        ]);

    // Attempts during lock do not increase counter
    expect($this->user->fresh()->failed_login_attempts)->toBe(5);
});

it('allows login after lock expires and resets failed_login_attempts', function (): void {
    $testNow = Carbon::parse('2026-11-19 10:00:00', 'UTC');
    Carbon::setTestNow($testNow);

    $this->user->update([
        'failed_login_attempts' => 5,
        'locked_until' => $testNow->copy()->addMinutes(15),
    ]);

    // Fast-forward 16 minutes
    Carbon::setTestNow($testNow->copy()->addMinutes(16));

    $response = $this->postJson('/api/auth/login', [
        'email' => 'lockout@example.test',
        'password' => 'CorrectPassword123!',
    ]);

    $response->assertStatus(200);

    $freshUser = $this->user->fresh();
    expect($freshUser->failed_login_attempts)->toBe(0);
    expect($freshUser->locked_until)->toBeNull();
});

it('does not reveal whether an unknown email exists', function (): void {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'unknown@example.test',
        'password' => 'WrongPassword',
    ]);

    $response->assertStatus(401)
        ->assertJson(['message' => 'The email or password is incorrect.']);
});
