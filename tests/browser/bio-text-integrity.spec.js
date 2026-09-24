import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { inspectBioText, fixBioText, SAFE_FIXES } from '../../resources/js/utils/bioTextIntegrity.js';

// The editors' copy of the bio text check must agree with the server's
// (app/Support/BioTextIntegrity.php): both run this fixture. No browser needed.

const fixture = JSON.parse(fs.readFileSync(path.resolve('tests/Fixtures/bio-text-integrity-cases.json'), 'utf8'));

test.describe('bio text integrity (JS mirror)', () => {
    for (const testCase of fixture.cases) {
        test(testCase.name, () => {
            const report = inspectBioText(testCase.input, testCase.format);
            expect(report.issues.map((issue) => issue.kind)).toEqual(testCase.kinds);
            expect(report.clean).toBe(testCase.kinds.length === 0);

            const fixed = fixBioText(testCase.input, testCase.format);
            expect(fixed).toBe(testCase.fixed);
            expect(fixBioText(testCase.input, testCase.format, SAFE_FIXES)).toBe(testCase.fixed_safe);
            expect(fixBioText(fixed, testCase.format)).toBe(fixed);
        });
    }

    test('samples match the server copy', () => {
        const report = inspectBioText("Une prÃ©sence qui met Ã l'aise, sans dÃ©tour.", 'text');
        expect(report.issues[0].count).toBe(3);
        expect(report.issues[0].samples).toEqual([
            { found: 'prÃ©sence', fixed: 'présence' },
            { found: 'Ã', fixed: 'à' },
            { found: 'dÃ©tour.', fixed: 'détour.' },
        ]);
    });
});
