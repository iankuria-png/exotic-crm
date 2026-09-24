<?php

namespace Tests\Unit\Support;

use App\Support\BioTextIntegrity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BioTextIntegrityTest extends TestCase
{
    public static function fixtureCases(): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/bio-text-integrity-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($fixture['cases'])->mapWithKeys(fn (array $case) => [$case['name'] => [$case]])->all();
    }

    #[DataProvider('fixtureCases')]
    public function test_fixture_case(array $case): void
    {
        $report = BioTextIntegrity::inspect($case['input'], $case['format']);

        $this->assertSame($case['kinds'], array_column($report['issues'], 'kind'));
        $this->assertSame($case['kinds'] === [], $report['clean']);
        $this->assertSame($case['fixed'], BioTextIntegrity::fix($case['input'], $case['format']));
        $this->assertSame($case['fixed_safe'], BioTextIntegrity::fix($case['input'], $case['format'], BioTextIntegrity::SAFE_FIXES));
    }

    #[DataProvider('fixtureCases')]
    public function test_fixing_twice_changes_nothing_more(array $case): void
    {
        $once = BioTextIntegrity::fix($case['input'], $case['format']);

        $this->assertSame($once, BioTextIntegrity::fix($once, $case['format']));
    }

    #[DataProvider('fixtureCases')]
    public function test_every_fixable_issue_is_gone_after_fixing(array $case): void
    {
        $after = BioTextIntegrity::inspect(BioTextIntegrity::fix($case['input'], $case['format']), $case['format']);

        $this->assertSame([], array_values(array_filter($after['issues'], fn (array $issue) => $issue['fixable'])));
    }

    public function test_samples_show_each_garbled_word_next_to_its_repair(): void
    {
        $report = BioTextIntegrity::inspect('Une prÃ©sence qui met Ã l\'aise, sans dÃ©tour.', BioTextIntegrity::FORMAT_TEXT);

        $this->assertSame(3, $report['issues'][0]['count']);
        $this->assertSame([
            ['found' => 'prÃ©sence', 'fixed' => 'présence'],
            ['found' => 'Ã', 'fixed' => 'à'],
            ['found' => 'dÃ©tour.', 'fixed' => 'détour.'],
        ], $report['issues'][0]['samples']);
    }

    public function test_fixing_invisible_characters_alone_keeps_garbled_accents_recoverable(): void
    {
        // "’" read as Latin-1 is "â" plus two control characters.
        $fixed = BioTextIntegrity::fix("met à l\u{00E2}\u{0080}\u{0099}aise", BioTextIntegrity::FORMAT_TEXT, [BioTextIntegrity::INVISIBLE_CHARACTERS]);

        $this->assertSame('met à l’aise', $fixed);
    }

    public function test_needs_fix_only_reports_safe_fixes_by_default(): void
    {
        $this->assertTrue(BioTextIntegrity::needsFix('<p>dÃ©tour</p>'));
        $this->assertFalse(BioTextIntegrity::needsFix('<p>Sweet 💋</p>'));
        $this->assertFalse(BioTextIntegrity::needsFix('<p>Sans détour.</p>'));
    }
}
