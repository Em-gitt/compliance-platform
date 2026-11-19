<?php

use App\Models\AiFeedback;
use App\Models\AiQuestion;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Feedback Corp',
        'organization_type' => 'private_company',
        'industry' => 'manufacturing',
        'state' => '10',
        'municipality' => 'Durango',
        'employees_count' => 50,
        'business_activities' => ['31-33'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin User',
        'email' => 'admin@feedback.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Alice User',
        'email' => 'alice@feedback.test',
        'password' => 'password',
        'role' => 'compliance_staff',
    ]);

    $this->otherUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Bob User',
        'email' => 'bob@feedback.test',
        'password' => 'password',
        'role' => 'general_employee',
    ]);

    $this->question = AiQuestion::query()->create([
        'user_id' => $this->user->id,
        'question' => 'What is the fire extinguisher requirement?',
        'answer' => 'According to NOM-002-STPS-2010, extinguishers must be inspected monthly.',
        'status' => 'answered',
        'citations' => [],
        'latency_ms' => 450,
    ]);
});

test('user can submit useful rating and receives exact message', function () {
    $response = $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'useful',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Thanks for your feedback.',
        ]);

    $this->assertDatabaseHas('ai_feedback', [
        'ai_question_id' => $this->question->id,
        'user_id' => $this->user->id,
        'rating' => 'useful',
        'comment' => null,
    ]);
});

test('user can submit not_useful rating with optional comment', function () {
    $response = $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'not_useful',
        'comment' => 'Missing specific section citations for verification.',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Thanks for your feedback.',
        ]);

    $this->assertDatabaseHas('ai_feedback', [
        'ai_question_id' => $this->question->id,
        'user_id' => $this->user->id,
        'rating' => 'not_useful',
        'comment' => 'Missing specific section citations for verification.',
    ]);
});

test('submitting feedback again updates the existing record', function () {
    $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'useful',
    ])->assertStatus(200);

    $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'not_useful',
        'comment' => 'Changed my mind, incomplete answer.',
    ])->assertStatus(200);

    expect(AiFeedback::where('ai_question_id', $this->question->id)->count())->toBe(1);

    $feedback = AiFeedback::where('ai_question_id', $this->question->id)->first();
    expect($feedback->rating)->toBe('not_useful')
        ->and($feedback->comment)->toBe('Changed my mind, incomplete answer.');
});

test('cannot rate someone elses answer and returns exact 403 message', function () {
    $response = $this->actingAs($this->otherUser)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'useful',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You can only rate your own answers.',
        ]);

    $this->assertDatabaseMissing('ai_feedback', [
        'ai_question_id' => $this->question->id,
    ]);
});

test('invalid rating returns exact 422 message', function () {
    $response = $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'somewhat_useful',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Select Useful or Not useful.',
        ]);
});

test('comment longer than 500 characters returns exact 422 message', function () {
    $response = $this->actingAs($this->user)->postJson("/api/assistant/questions/{$this->question->id}/feedback", [
        'rating' => 'not_useful',
        'comment' => str_repeat('a', 501),
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Comments can be up to 500 characters.',
        ]);
});

test('administrator can export feedback as CSV', function () {
    AiFeedback::create([
        'ai_question_id' => $this->question->id,
        'user_id' => $this->user->id,
        'rating' => 'not_useful',
        'comment' => 'Needs clarification',
    ]);

    $response = $this->actingAs($this->admin)->get('/api/admin/ai-feedback/export');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="ai-feedback.csv"');

    expect($response->getContent())->toContain('What is the fire extinguisher requirement?')
        ->and($response->getContent())->toContain('Needs clarification');
});

test('non-administrator cannot export feedback', function () {
    $this->actingAs($this->user)->getJson('/api/admin/ai-feedback/export')
        ->assertStatus(403);
});
