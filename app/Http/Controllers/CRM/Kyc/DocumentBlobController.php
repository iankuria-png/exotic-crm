<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\Kyc\KycDocumentService;
use Illuminate\Http\Request;

class DocumentBlobController extends Controller
{
    public function __construct(private readonly KycDocumentService $documentService)
    {
    }

    public function show(Request $request, KycDocument $document)
    {
        $document->loadMissing(['subject.client', 'blob']);
        $signedUrl = $this->documentService->signedViewUrl($document, $request->user());

        if ($document->storage_driver === 's3') {
            return redirect()->away($signedUrl);
        }

        try {
            $contents = $this->documentService->decryptBlob($document);
        } catch (\Throwable) {
            return response()->json(['message' => 'Document unreadable — decryption failed'], 422);
        }

        return response($contents, 200, [
            'Content-Type' => (string) $document->mime,
            'Content-Disposition' => 'inline; filename="kyc-document-' . $document->id . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
