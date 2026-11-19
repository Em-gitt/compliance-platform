<?php

use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Session Timeout Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Timeout User',
        'email' => 'timeout@example.test',
        'password' => 'SecurePass123!',
        'role' => 'administrator',
        'is_active' => true,
    ]);
});

it('ends session and returns 401 with exact message after 30 minutes of inactivity', function (): void {
    $testNow = Carbon::parse('2026-11-19 10:00:00', 'UTC');
    Carbon::setTestNow($testNow);

    $headers = ['referer' => 'http://localhost'];

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'timeout@example.test',
        'password' => 'SecurePass123!',
    ], $headers);
    $loginResponse->assertStatus(200);

    $sessionCookie = null;
    foreach ($loginResponse->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            $sessionCookie = $c->getValue();
            break;
        }
    }

    // Access immediately with cookie - should succeed
    $immediateResponse = $this->call(
        'GET',
        '/api/auth/me',
        [],
        [config('session.cookie') => $sessionCookie],
        [],
        ['HTTP_REFERER' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']
    );
    $immediateResponse->assertStatus(200);

    // Advance 31 minutes without activity
    Carbon::setTestNow($testNow->copy()->addMinutes(31));
    auth()->forgetGuards();
    session()->flush();
    $handler = app('session')->getHandler();
    $handler->gc(1800);

    $expiredResponse = $this->call(
        'GET',
        '/api/auth/me',
        [],
        [config('session.cookie') => $sessionCookie],
        [],
        ['HTTP_REFERER' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']
    );

    $expiredResponse->assertStatus(401)
        ->assertJson([
            'message' => 'Your session expired after 30 minutes of inactivity. Please sign in again.',
        ]);
});

it('renews the 30-minute window on authenticated requests', function (): void {
    $testNow = Carbon::parse('2026-11-19 10:00:00', 'UTC');
    Carbon::setTestNow($testNow);

    $headers = ['referer' => 'http://localhost'];

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'timeout@example.test',
        'password' => 'SecurePass123!',
    ], $headers);
    $loginResponse->assertStatus(200);

    $sessionCookie = null;
    foreach ($loginResponse->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            $sessionCookie = $c->getValue();
            break;
        }
    }

    // Advance 20 minutes and make authenticated request
    Carbon::setTestNow($testNow->copy()->addMinutes(20));
    auth()->forgetGuards();

    $r1 = $this->call(
        'GET',
        '/api/auth/me',
        [],
        [config('session.cookie') => $sessionCookie],
        [],
        ['HTTP_REFERER' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']
    );
    $r1->assertStatus(200);

    foreach ($r1->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            $sessionCookie = $c->getValue();
            break;
        }
    }

    // Advance another 20 minutes (40m from login, but 20m from last activity)
    Carbon::setTestNow($testNow->copy()->addMinutes(40));
    auth()->forgetGuards();

    $r2 = $this->call(
        'GET',
        '/api/auth/me',
        [],
        [config('session.cookie') => $sessionCookie],
        [],
        ['HTTP_REFERER' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']
    );
    $r2->assertStatus(200);

    foreach ($r2->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            $sessionCookie = $c->getValue();
            break;
        }
    }

    // Advance 31 minutes from last activity (71m from login)
    Carbon::setTestNow($testNow->copy()->addMinutes(71));
    auth()->forgetGuards();
    session()->flush();
    $handler = app('session')->getHandler();
    $handler->gc(1800);

    $r3 = $this->call(
        'GET',
        '/api/auth/me',
        [],
        [config('session.cookie') => $sessionCookie],
        [],
        ['HTTP_REFERER' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']
    );

    $r3->assertStatus(401)
        ->assertJson([
            'message' => 'Your session expired after 30 minutes of inactivity. Please sign in again.',
        ]);
});
