<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API Controller for viewing maker-checker audit trail.
 *
 * Provides a filterable, exportable endpoint for audit logs.
 */
class MakerCheckerAuditController extends Controller
{
    /**
     * Get audit logs with optional filters.
     *
     * @queryParam date_from string Filter logs from this date (Y-m-d)
     * @queryParam date_to string Filter logs to this date (Y-m-d)
     * @queryParam actor_id integer Filter by actor ID
     * @queryParam action string Filter by action (approved, rejected, cancelled, etc.)
     * @queryParam request_id integer Filter by request ID
     * @queryParam format string Response format: json (default) or csv
     * @queryParam per_page integer Items per page (default: 15)
     */
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $tableName = config('maker-checker.audit.table_name', 'maker_checker_audit_logs');

        $query = DB::table($tableName);

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from').' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to').' 23:59:59');
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->integer('actor_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('request_id')) {
            $query->where('request_id', $request->integer('request_id'));
        }

        $query->orderByDesc('created_at');

        $format = $request->input('format', 'json');

        if ($format === 'csv') {
            return $this->exportCsv($query);
        }

        $perPage = $request->integer('per_page', 15);
        $results = $query->paginate($perPage);

        return response()->json([
            'data' => collect($results->items())->map(fn($item) => [
                'id' => $item->id,
                'request_id' => $item->request_id,
                'actor_type' => $item->actor_type,
                'actor_id' => $item->actor_id,
                'action' => $item->action,
                'previous_status' => $item->previous_status,
                'new_status' => $item->new_status,
                'ip_address' => $item->ip_address,
                'metadata' => json_decode($item->metadata ?? '{}', true),
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    /**
     * Export audit logs as CSV.
     *
     * @param  Builder  $query
     */
    protected function exportCsv($query): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maker_checker_audit_'.now()->format('Y-m-d_His').'.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // Write CSV header
            fputcsv($handle, [
                'ID',
                'Request ID',
                'Actor Type',
                'Actor ID',
                'Action',
                'Previous Status',
                'New Status',
                'IP Address',
                'Metadata',
                'Created At',
            ]);

            // Write rows in chunks
            $query->orderByDesc('created_at')->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->request_id,
                        $row->actor_type,
                        $row->actor_id,
                        $row->action,
                        $row->previous_status,
                        $row->new_status,
                        $row->ip_address,
                        $row->metadata,
                        $row->created_at,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
