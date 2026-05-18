<?php

namespace App\Http\Controllers\API\Kyc;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycDocumentService;
use Illuminate\Http\Request;

class BlobUploadController extends Controller
{
    public function __construct(private readonly KycDocumentService $documentService)
    {
    }

    public function store(Request $request)
    {
        $claims = (array) $request->attributes->get('kyc_upload_claims', []);
        $request->validate([
            'file' => 'required|file',
        ]);

        $document = $this->documentService->storeDbUploadFromToken($claims, $request->file('file'));

        return response()->json([
            'success' => true,
            'document_id' => (int) $document->id,
            'subject_id' => (int) $document->subject_id,
        ], 201);
    }
}
