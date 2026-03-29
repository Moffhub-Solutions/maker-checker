<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Moffhub\MakerChecker\Tests\BaseTestCase;
use Moffhub\MakerChecker\Tests\Fixtures\Models\User;

class AuditTrailExportTest extends BaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }

    protected function insertAuditLog(array $attributes = []): void
    {
        $defaults = [
            'request_id' => 1,
            'actor_type' => User::class,
            'actor_id' => $this->admin->id,
            'action' => 'approved',
            'previous_status' => 'pending',
            'new_status' => 'approved',
            'ip_address' => '127.0.0.1',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('maker_checker_audit_logs')->insert(array_merge($defaults, $attributes));
    }

    public function test_can_list_audit_logs(): void
    {
        $this->insertAuditLog();
        $this->insertAuditLog(['action' => 'rejected', 'new_status' => 'rejected']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/audit');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'request_id',
                        'actor_type',
                        'actor_id',
                        'action',
                        'previous_status',
                        'new_status',
                        'ip_address',
                        'metadata',
                        'created_at',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_can_filter_by_action(): void
    {
        $this->insertAuditLog(['action' => 'approved']);
        $this->insertAuditLog(['action' => 'rejected']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/audit?action=approved');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('approved', $response->json('data.0.action'));
    }

    public function test_can_filter_by_request_id(): void
    {
        $this->insertAuditLog(['request_id' => 1]);
        $this->insertAuditLog(['request_id' => 2]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/audit?request_id=1');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_can_filter_by_actor_id(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'role' => 'user',
        ]);

        $this->insertAuditLog(['actor_id' => $this->admin->id]);
        $this->insertAuditLog(['actor_id' => $otherUser->id]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/audit?actor_id={$this->admin->id}");

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_can_filter_by_date_range(): void
    {
        $this->insertAuditLog(['created_at' => now()->subDays(5)]);
        $this->insertAuditLog(['created_at' => now()->subDays(1)]);
        $this->insertAuditLog(['created_at' => now()->subDays(10)]);

        $dateFrom = now()->subDays(7)->format('Y-m-d');
        $dateTo = now()->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/maker-checker/audit?date_from={$dateFrom}&date_to={$dateTo}");

        $response->assertOk();
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_supports_pagination(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->insertAuditLog(['request_id' => $i + 1]);
        }

        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/audit?per_page=5');

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
        $this->assertEquals(20, $response->json('meta.total'));
        $this->assertEquals(4, $response->json('meta.last_page'));
    }

    public function test_csv_export(): void
    {
        $this->insertAuditLog(['action' => 'approved']);
        $this->insertAuditLog(['action' => 'rejected']);

        $response = $this->actingAs($this->admin)
            ->get('/api/maker-checker/audit?format=csv');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $response->assertHeader('Content-Disposition');

        $content = $response->streamedContent();
        $this->assertStringContainsString('ID', $content);
        $this->assertStringContainsString('approved', $content);
        $this->assertStringContainsString('rejected', $content);
    }

    public function test_empty_audit_log(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maker-checker/audit');

        $response->assertOk();
        $this->assertEquals(0, $response->json('meta.total'));
        $this->assertEmpty($response->json('data'));
    }
}
