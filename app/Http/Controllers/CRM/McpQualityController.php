<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpSchemaAudit;
use App\Services\Mcp\Knowledge\SchemaDriftDetector;

class McpQualityController extends Controller
{
    public function index()
    {
        return response()->json(['latest_audit' => McpSchemaAudit::query()->latest('completed_at')->first(), 'proposals' => []]);
    }

    public function audit(SchemaDriftDetector $detector)
    {
        abort_unless(request()->user()?->role === 'admin', 403);

        return response()->json(['audit' => $detector->audit()]);
    }
}
