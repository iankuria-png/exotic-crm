<?php

namespace App\Services\University;

use App\Models\University\Attempt;
use App\Models\University\Certificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateService
{
    public function issue(Attempt $attempt): ?Certificate
    {
        $attempt->loadMissing(['user', 'certification.course', 'certificate']);

        if (!$attempt->passed || !$attempt->submitted_at) {
            return null;
        }

        if ($attempt->certificate) {
            return $attempt->certificate;
        }

        $certificate = Certificate::create([
            'user_id' => $attempt->user_id,
            'certification_id' => $attempt->certification_id,
            'attempt_id' => $attempt->id,
            'certificate_code' => $this->generateCode(),
            'issued_at' => now(),
            'expires_at' => now()->addMonths((int) $attempt->certification->validity_months),
        ]);

        $certificate->pdf_path = $this->renderCertificate($certificate->fresh(['user', 'certification.course', 'attempt']));
        $certificate->save();

        return $certificate->fresh(['user', 'certification.course', 'attempt']);
    }

    private function generateCode(): string
    {
        do {
            $code = 'EOU-' . now()->format('Ym') . '-' . Str::upper(Str::random(8));
        } while (Certificate::query()->where('certificate_code', $code)->exists());

        return $code;
    }

    private function renderCertificate(Certificate $certificate): string
    {
        $path = 'university/certificates/' . $certificate->certificate_code . '.pdf';
        $html = view('university.certificate', [
            'certificate' => $certificate,
            'verifyUrl' => url('/university/verify/' . $certificate->certificate_code),
        ])->render();

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');
            Storage::disk('public')->put($path, $pdf->output());

            return $path;
        }

        Storage::disk('public')->put($path, $html);

        return $path;
    }
}
