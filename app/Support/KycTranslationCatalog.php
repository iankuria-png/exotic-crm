<?php

namespace App\Support;

class KycTranslationCatalog
{
    public static function entries(): array
    {
        return [
            ['key' => 'verify.title', 'source' => 'Verify your account'],
            ['key' => 'verify.subtitle', 'source' => 'Upload your ID and a selfie so our team can verify your account. You can keep publishing while review is in progress.'],
            ['key' => 'verify.id_front', 'source' => 'Government ID (front)'],
            ['key' => 'verify.id_back', 'source' => 'Government ID (back)'],
            ['key' => 'verify.selfie', 'source' => 'Selfie'],
            ['key' => 'verify.pick_file', 'source' => 'Choose file'],
            ['key' => 'verify.uploading', 'source' => 'Uploading…'],
            ['key' => 'verify.retrying', 'source' => 'Retrying upload…'],
            ['key' => 'verify.complete', 'source' => 'Upload complete'],
            ['key' => 'verify.failed', 'source' => 'Upload failed. Please try again.'],
            ['key' => 'verify.offline', 'source' => 'You appear to be offline. Reconnect and try again.'],
            ['key' => 'status.unverified', 'source' => 'Not verified yet'],
            ['key' => 'status.in_review', 'source' => 'In review'],
            ['key' => 'status.info_requested', 'source' => 'More information needed'],
            ['key' => 'status.approved', 'source' => 'Verified'],
            ['key' => 'status.rejected', 'source' => 'Verification rejected'],
            ['key' => 'status.expired', 'source' => 'Re-verification required'],
            ['key' => 'banner.approved', 'source' => 'Your account is verified. The public verified badge is now visible on your profile.'],
            ['key' => 'banner.pending', 'source' => 'Your documents have been received and are waiting for review.'],
            ['key' => 'banner.info_requested', 'source' => 'We need clearer or updated documents before we can approve your account.'],
            ['key' => 'banner.rejected', 'source' => 'Your documents were rejected. Please review the message and upload new files.'],
            ['key' => 'banner.exempt', 'source' => 'Verification is not currently required for this account.'],
            ['key' => 'faq.link', 'source' => 'Read the KYC FAQ'],
            ['key' => 'faq.title', 'source' => 'KYC frequently asked questions'],
            ['key' => 'faq.q1', 'source' => 'Why do I need to verify my account?'],
            ['key' => 'faq.a1', 'source' => 'Verification helps us confirm identity and age, protect the platform, and award the public verified badge once review is complete.'],
            ['key' => 'faq.q2', 'source' => 'Will verification block my profile from going live?'],
            ['key' => 'faq.a2', 'source' => 'No. In v1, verification is soft enforcement. You can still register, pay, and publish while the CRM review team works the queue.'],
            ['key' => 'faq.q3', 'source' => 'What files should I upload?'],
            ['key' => 'faq.a3', 'source' => 'Upload a clear photo of a government-issued ID and a selfie that matches the person on the ID. Some markets also request the back of the ID.'],
            ['key' => 'faq.q4', 'source' => 'How long does review take?'],
            ['key' => 'faq.a4', 'source' => 'Review time depends on queue volume, but the CRM reviewer team can see your submission as soon as all required files are uploaded.'],
            ['key' => 'faq.q5', 'source' => 'What happens if my documents are rejected?'],
            ['key' => 'faq.a5', 'source' => 'You will receive a message explaining what to fix, and you can upload a replacement without losing access to your account.'],
        ];
    }
}
