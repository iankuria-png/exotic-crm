<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServer;
use Illuminate\Http\Request;

class McpController extends Controller
{
    public function __construct(private readonly McpServer $server) {}

    public function __invoke(Request $request)
    {
        return $this->server->handle($request);
    }
}
