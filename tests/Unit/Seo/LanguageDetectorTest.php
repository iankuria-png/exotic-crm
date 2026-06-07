<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\LanguageDetector;
use Tests\TestCase;

class LanguageDetectorTest extends TestCase
{
    public function test_detects_english_text(): void
    {
        $result = app(LanguageDetector::class)->detect('You can find me in Nairobi and contact me for a warm, direct introduction.');

        $this->assertSame('en', $result['language']);
        $this->assertGreaterThan(0.2, $result['confidence']);
    }

    public function test_detects_french_text(): void
    {
        $result = app(LanguageDetector::class)->detect('Bonjour, je suis discrète et disponible avec vous dans le centre-ville.');

        $this->assertSame('fr', $result['language']);
        $this->assertGreaterThan(0.2, $result['confidence']);
    }

    public function test_detects_portuguese_text(): void
    {
        $result = app(LanguageDetector::class)->detect('Olá, voce encontra uma companhia discreta com atendimento em português.');

        $this->assertSame('pt', $result['language']);
        $this->assertGreaterThan(0.2, $result['confidence']);
    }

    public function test_detects_swahili_text(): void
    {
        $result = app(LanguageDetector::class)->detect('Karibu, ninatoa huduma ya kirafiki kwa wateja wako mjini Nairobi.');

        $this->assertSame('sw', $result['language']);
        $this->assertGreaterThan(0.2, $result['confidence']);
    }
}
