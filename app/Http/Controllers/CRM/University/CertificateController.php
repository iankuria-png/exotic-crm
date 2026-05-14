<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Certificate;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    use SerializesUniversityPayloads;

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function download(Request $request, string $code)
    {
        $certificate = Certificate::query()
            ->with(['user', 'certification.course', 'attempt'])
            ->where('certificate_code', $code)
            ->firstOrFail();

        if ($request->user() && $request->user()->id !== $certificate->user_id && !$this->isUniversityAdmin($request)) {
            abort(403);
        }

        if (!$certificate->pdf_path || !Storage::disk('public')->exists($certificate->pdf_path)) {
            abort(404);
        }

        return Storage::disk('public')->download($certificate->pdf_path, $certificate->certificate_code . '.pdf');
    }

    public function verify(string $code)
    {
        $certificate = Certificate::query()
            ->with(['user:id,name', 'certification.course', 'attempt'])
            ->where('certificate_code', $code)
            ->firstOrFail();

        return response()->json([
            'certificate' => [
                ...$this->serializeCertificate($certificate),
                'holder_name' => $certificate->user?->name,
                'certification_title' => $certificate->certification?->title,
                'course_title' => $certificate->certification?->course?->title,
                'score_pct' => $certificate->attempt?->score_pct,
            ],
        ]);
    }

    public function revoke(Request $request, Certificate $certificate)
    {
        $before = $certificate->toArray();
        $certificate->forceFill(['revoked_at' => now()])->save();
        $this->auditService->fromSystemRequest($request, 'university_certificate_revoked', 'university_certificate', (int) $certificate->id, $before, $certificate->fresh()->toArray(), 'Revoked university certificate');

        return response()->json([
            'message' => 'Certificate revoked.',
            'certificate' => $this->serializeCertificate($certificate->fresh()),
        ]);
    }
}
