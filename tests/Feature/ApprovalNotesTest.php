<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerApprovalNote;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\Post;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class ApprovalNotesTest extends BaseTestCase
{
    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'role' => 'user',
        ]);
    }

    protected function createRequest(array $attributes = []): MakerCheckerRequest
    {
        $maker = $attributes['maker'] ?? $this->user;
        unset($attributes['maker']);

        $defaults = [
            'description' => 'Test request',
            'type' => RequestType::CREATE,
            'status' => RequestStatus::PENDING,
            'subject_type' => Post::class,
            'maker_type' => User::class,
            'maker_id' => $maker->id,
            'made_at' => now(),
            'payload' => ['title' => 'Test', 'content' => 'Content', 'user_id' => $maker->id],
        ];

        $merged = array_merge($defaults, $attributes);
        $code = $merged['code'] ?? (string) Str::uuid();
        unset($merged['code']);

        $request = new MakerCheckerRequest($merged);
        $request->code = $code;
        $request->save();

        return $request;
    }

    public function test_approve_with_note_saves_note(): void
    {
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/requests/{$request->id}/approve", [
                'role' => 'admin',
                'note' => 'Looks good, approved.',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('maker_checker_approval_notes', [
            'request_id' => $request->id,
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'action' => 'approved',
            'note' => 'Looks good, approved.',
        ]);
    }

    public function test_reject_with_note_saves_note(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/requests/{$request->id}/reject", [
                'remarks' => 'Not good',
                'note' => 'Need more details on this.',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('maker_checker_approval_notes', [
            'request_id' => $request->id,
            'action' => 'rejected',
            'note' => 'Need more details on this.',
        ]);
    }

    public function test_cancel_with_note_saves_note(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->user)
            ->postJson("/api/maker-checker/requests/{$request->id}/cancel", [
                'note' => 'No longer needed.',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('maker_checker_approval_notes', [
            'request_id' => $request->id,
            'action' => 'cancelled',
            'note' => 'No longer needed.',
        ]);
    }

    public function test_approve_without_note_does_not_create_note(): void
    {
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/maker-checker/requests/{$request->id}/approve", [
                'role' => 'admin',
            ]);

        $response->assertOk();
        $this->assertEquals(0, MakerCheckerApprovalNote::count());
    }

    public function test_notes_included_in_base_format_response(): void
    {
        $request = $this->createRequest(['required_approvals' => ['admin' => 1]]);

        // Add a note directly
        MakerCheckerApprovalNote::create([
            'request_id' => $request->id,
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'action' => 'approved',
            'note' => 'All good.',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/requests/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('data.notes.0.note', 'All good.')
            ->assertJsonPath('data.notes.0.action', 'approved');
    }

    public function test_notes_relationship_on_request(): void
    {
        $request = $this->createRequest();

        MakerCheckerApprovalNote::create([
            'request_id' => $request->id,
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'action' => 'approved',
            'note' => 'First note.',
        ]);

        MakerCheckerApprovalNote::create([
            'request_id' => $request->id,
            'user_type' => User::class,
            'user_id' => $this->user->id,
            'action' => 'comment',
            'note' => 'Second note.',
        ]);

        $request->refresh();
        $this->assertCount(2, $request->notes);
        $this->assertEquals('First note.', $request->notes->first()->note);
    }

    public function test_approval_note_model_has_user_relationship(): void
    {
        $request = $this->createRequest();

        $note = MakerCheckerApprovalNote::create([
            'request_id' => $request->id,
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'action' => 'approved',
            'note' => 'Test note.',
        ]);

        $this->assertInstanceOf(User::class, $note->user);
        $this->assertEquals($this->admin->id, $note->user->id);
    }
}
