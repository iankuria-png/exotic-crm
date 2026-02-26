<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class PaymentImportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_validation_duplicate_and_suggested_match_summary(): void
    {
        $platform = $this->createPlatform('Kenya', '254');
        $user = $this->createUser('sales', [$platform->id]);

        \App\Models\Client::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Matched Client',
            'phone_normalized' => '254711111111',
            'profile_status' => 'publish',
            'wp_post_id' => 10001,
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000001',
            'amount' => 1400,
            'currency' => 'KES',
            'transaction_reference' => 'ABC123',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $csv = implode("\n", [
            'phone,amount,currency,transaction_reference,status,payment_date',
            '0711111111,1200,KES,TXN001,completed,2026-02-01',
            '0722222222,1500,KES,ABC123,completed,2026-02-02',
            ',foo,KES,,completed,',
        ]);

        $response = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('kenya-payments.csv', $csv),
            'has_header' => true,
            'reason' => 'Preview manual payment import',
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.total_rows', 3)
            ->assertJsonPath('summary.valid_rows', 1)
            ->assertJsonPath('summary.duplicate_rows', 1)
            ->assertJsonPath('summary.invalid_rows', 1);

        $batchId = (int) $response->json('batch_id');
        $this->assertGreaterThan(0, $batchId);

        $this->assertDatabaseHas('payment_import_batches', [
            'id' => $batchId,
            'platform_id' => $platform->id,
            'status' => 'previewed',
        ]);

        $this->assertDatabaseHas('payment_import_rows', [
            'batch_id' => $batchId,
            'status' => 'duplicate',
            'duplicate_type' => 'duplicate_existing_reference',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_IMPORT_PREVIEW,
            'entity_type' => 'payment_import_batch',
            'entity_id' => $batchId,
        ]);

        $rows = collect($response->json('rows'));
        $validRow = $rows->firstWhere('status', 'valid');
        $this->assertNotNull($validRow);
        $this->assertSame('auto_high', data_get($validRow, 'suggested_match.confidence'));
    }

    public function test_commit_persists_imported_payments_with_provenance_and_is_idempotent(): void
    {
        $platform = $this->createPlatform('Tanzania', '255');
        $user = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $csv = implode("\n", [
            'phone,amount,currency,transaction_reference,status,payment_date',
            '0712121212,1800,TZS,TXN-TZ-001,completed,2026-02-05',
        ]);

        $previewResponse = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('tz-payments.csv', $csv),
            'has_header' => true,
            'reason' => 'Preview Tanzania records',
        ]);

        $previewResponse->assertOk()->assertJsonPath('summary.valid_rows', 1);
        $batchId = (int) $previewResponse->json('batch_id');

        $commitResponse = $this->postJson('/api/crm/payments/import/commit', [
            'batch_id' => $batchId,
            'reason' => 'Commit Tanzania records',
        ]);

        $commitResponse->assertOk()
            ->assertJsonPath('summary.created_now', 1)
            ->assertJsonPath('summary.committed_rows', 1);

        $payment = Payment::query()->where('import_batch_id', $batchId)->first();
        $this->assertNotNull($payment);
        $this->assertSame('excel_import', $payment->source);
        $this->assertNotNull($payment->import_legacy_hash);
        $this->assertSame($batchId, data_get($payment->raw_payload, 'import.batch_id'));
        $this->assertSame('excel_import', data_get($payment->raw_payload, 'source'));

        $secondCommitResponse = $this->postJson('/api/crm/payments/import/commit', [
            'batch_id' => $batchId,
            'reason' => 'Retry commit should be idempotent',
        ]);

        $secondCommitResponse->assertOk()
            ->assertJsonPath('summary.created_now', 0);

        $this->assertSame(1, Payment::query()->where('import_batch_id', $batchId)->count());

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_IMPORT_COMMIT,
            'entity_type' => 'payment_import_batch',
            'entity_id' => $batchId,
        ]);
    }

    public function test_preview_supports_xlsx_uploads(): void
    {
        $platform = $this->createPlatform('Uganda', '256');
        $user = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($user);

        $xlsxContent = $this->buildSimpleXlsx([
            ['phone', 'amount', 'currency', 'transaction_reference', 'status'],
            ['0700000001', '2200', 'UGX', 'UGX001', 'completed'],
        ]);

        $response = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('uganda-payments.xlsx', $xlsxContent),
            'has_header' => true,
            'reason' => 'Preview XLSX import',
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.valid_rows', 1)
            ->assertJsonPath('summary.invalid_rows', 0);
    }

    private function createUser(string $role = 'sales', array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function createPlatform(string $name, string $phonePrefix): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'is_active' => true,
            'phone_prefix' => $phonePrefix,
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function buildSimpleXlsx(array $rows): string
    {
        $sharedStrings = [];
        $sharedIndex = [];
        $sheetRowsXml = '';

        foreach ($rows as $rowNumber => $rowValues) {
            $cellsXml = '';
            foreach ($rowValues as $columnIndex => $value) {
                $stringValue = (string) $value;
                if (!array_key_exists($stringValue, $sharedIndex)) {
                    $sharedIndex[$stringValue] = count($sharedStrings);
                    $sharedStrings[] = $stringValue;
                }

                $cellRef = $this->columnLetter($columnIndex) . ($rowNumber + 1);
                $cellsXml .= sprintf(
                    '<c r="%s" t="s"><v>%d</v></c>',
                    $cellRef,
                    $sharedIndex[$stringValue]
                );
            }

            $sheetRowsXml .= sprintf('<row r="%d">%s</row>', $rowNumber + 1, $cellsXml);
        }

        $sharedXmlParts = array_map(
            fn(string $value) => '<si><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></si>',
            $sharedStrings
        );

        $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
XML;

        $rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

        $workbook = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;

        $workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML;

        $worksheet = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    {$sheetRowsXml}
  </sheetData>
</worksheet>
XML;

        $sharedStringsXml = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"%d\" uniqueCount=\"%d\">%s</sst>",
            count($sharedStrings),
            count($sharedStrings),
            implode('', $sharedXmlParts)
        );

        $tempZip = tempnam(sys_get_temp_dir(), 'xlsx_import_');
        $zip = new ZipArchive();
        $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->close();

        $content = file_get_contents($tempZip);
        @unlink($tempZip);

        return $content === false ? '' : $content;
    }

    private function columnLetter(int $index): string
    {
        $index += 1;
        $letters = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
