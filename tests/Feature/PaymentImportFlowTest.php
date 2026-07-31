<?php

namespace Tests\Feature;

use App\Models\Client;
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

    public function test_orphan_paste_commit_preserves_thousands_and_backdated_completed_at(): void
    {
        $platform = $this->createPlatform('Tanzania', '255');
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $paste = implode("\n", [
            '47k',
            'T_5R6F4G-G4B5VXZMEZ',
            '',
            '28th july 2026',
            'yohana',
            'DGR951X4BM',
            '35,000',
            'renewal',
        ]);

        $preview = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'mode' => 'orphan_paste',
            'pasted_text' => $paste,
            'date_from' => '2026-07-28',
            'reason' => 'Import orphaned Tanzania payments',
            'source_owner' => 'Joanne',
        ]);

        $preview->assertOk()
            ->assertJsonPath('source_type', 'orphan_paste')
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.valid_rows', 2);

        $batchId = (int) $preview->json('batch_id');
        $commit = $this->postJson('/api/crm/payments/import/commit', [
            'batch_id' => $batchId,
            'reason' => 'Commit orphaned Tanzania payments',
        ]);

        $commit->assertOk()
            ->assertJsonPath('summary.created_now', 2)
            ->assertJsonPath('summary.committed_rows', 2);

        $payments = Payment::query()
            ->where('import_batch_id', $batchId)
            ->orderBy('amount')
            ->get();

        $this->assertSame([35000.0, 47000.0], $payments->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertTrue($payments->every(fn (Payment $payment) => $payment->source === 'orphan_manual_import'));
        $this->assertTrue($payments->every(fn (Payment $payment) => $payment->status === 'completed'));
        $this->assertTrue($payments->every(fn (Payment $payment) => $payment->completed_at?->toDateString() === '2026-07-28'));
        $this->assertSame('Joanne', data_get($payments->first()->raw_payload, 'import.source_owner'));

        $this->getJson("/api/crm/payments/import/kpis?platform_id={$platform->id}")
            ->assertOk()
            ->assertJsonPath('kpis.payments_imported', 2);
    }

    public function test_csv_decimal_comma_import_stays_scoped_to_existing_parser(): void
    {
        $platform = $this->createPlatform('France', '33');
        $sales = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($sales);

        $csv = implode("\n", [
            'phone,amount,currency,transaction_reference,status,payment_date',
            '0712121212,"35,50",EUR,DECIMAL-COMMA-1,completed,2026-07-28',
        ]);

        $preview = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('decimal-comma.csv', $csv),
            'has_header' => true,
            'reason' => 'Existing CSV decimal comma behavior',
        ]);

        $preview->assertOk()->assertJsonPath('rows.0.normalized_row.amount', 35.5);
    }

    public function test_orphan_paste_preview_uses_selected_market_currency_and_ignores_heading(): void
    {
        $platform = $this->createPlatform('Senegal', '221', 'XOF');
        $admin = $this->createUser('admin');
        $sourceOwner = User::query()->create([
            'name' => 'Daniel Kimani',
            'email' => 'daniel.kimani@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
        Sanctum::actingAs($admin);

        $preview = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'mode' => 'orphan_paste',
            'pasted_text' => implode("\n", [
                'transaction code',
                '47k',
                'T_5R6F4G-G4B5VXZMEZ',
                '',
                '74k',
                'xot-26aqr2qpr1y4j',
            ]),
            'reason' => 'Import orphaned Senegal payments',
            'source_owner_user_id' => $sourceOwner->id,
        ]);

        $preview->assertOk()
            ->assertJsonPath('currency', 'XOF')
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.valid_rows', 2)
            ->assertJsonPath('rows.0.normalized_row.amount', 47000)
            ->assertJsonPath('rows.0.normalized_row.currency', 'XOF')
            ->assertJsonPath('rows.0.normalized_row.client_name', null)
            ->assertJsonPath('rows.0.normalized_row.sender_name', null);

        $batchId = (int) $preview->json('batch_id');

        $commit = $this->postJson('/api/crm/payments/import/commit', [
            'batch_id' => $batchId,
            'reason' => 'Commit orphaned Senegal payments',
        ]);

        $commit->assertOk()->assertJsonPath('summary.created_now', 2);

        $payments = Payment::query()
            ->where('import_batch_id', $batchId)
            ->get();

        $this->assertTrue($payments->every(fn (Payment $payment) => $payment->currency === 'XOF'));
        $this->assertTrue($payments->every(fn (Payment $payment) => data_get($payment->raw_payload, 'import.source_owner') === 'Daniel Kimani'));
        $this->assertTrue($payments->every(fn (Payment $payment) => (int) data_get($payment->raw_payload, 'import.source_owner_user_id') === $sourceOwner->id));
    }

    public function test_orphan_paste_mode_is_admin_only_without_breaking_sales_file_import(): void
    {
        $platform = $this->createPlatform('Kenya', '254');
        $sales = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($sales);

        $csv = implode("\n", [
            'phone,amount,currency,transaction_reference,status',
            '0712121212,1800,KES,SALES-FILE-OK,completed',
        ]);

        $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('sales-file.csv', $csv),
            'has_header' => true,
            'reason' => 'Sales file import remains available',
        ])->assertOk();

        $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'mode' => 'orphan_paste',
            'pasted_text' => "47k\nT_SALES_BLOCKED",
            'reason' => 'Sales should not import orphan paste',
        ])->assertForbidden();
    }

    public function test_code_less_orphan_row_requires_match_then_commits(): void
    {
        $platform = $this->createPlatform('Tanzania', '255');
        $admin = $this->createUser('admin');
        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Latipha',
            'phone_normalized' => '255700000001',
            'profile_status' => 'publish',
            'wp_post_id' => 20001,
        ]);

        Sanctum::actingAs($admin);

        $preview = $this->postJson('/api/crm/payments/import/preview', [
            'platform_id' => $platform->id,
            'mode' => 'orphan_paste',
            'pasted_text' => '28th July 2026    Latipha    35000.',
            'reason' => 'Code-less manual orphan row',
        ]);

        $preview->assertOk()
            ->assertJsonPath('summary.needs_match_rows', 1)
            ->assertJsonPath('rows.0.status', 'needs_match');

        $rowId = (int) $preview->json('rows.0.id');
        $batchId = (int) $preview->json('batch_id');

        $match = $this->postJson('/api/crm/payments/import/row-match', [
            'row_id' => $rowId,
            'client_id' => $client->id,
        ]);

        $match->assertOk()->assertJsonPath('suggested_match.client_id', $client->id);
        $this->assertDatabaseHas('payment_import_rows', [
            'id' => $rowId,
            'status' => 'valid',
        ]);

        $commit = $this->postJson('/api/crm/payments/import/commit', [
            'batch_id' => $batchId,
            'reason' => 'Commit matched code-less orphan row',
        ]);

        $commit->assertOk()->assertJsonPath('summary.created_now', 1);

        $payment = Payment::query()->where('import_batch_id', $batchId)->firstOrFail();
        $this->assertSame($client->id, (int) $payment->client_id);
        $this->assertSame(35000.0, (float) $payment->amount);
    }

    private function createUser(string $role = 'sales', array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function createPlatform(string $name, string $phonePrefix, string $currency = 'KES'): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name).'-'.Str::random(6).'.test',
            'country' => $name,
            'is_active' => true,
            'phone_prefix' => $phonePrefix,
            'currency_code' => $currency,
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
                if (! array_key_exists($stringValue, $sharedIndex)) {
                    $sharedIndex[$stringValue] = count($sharedStrings);
                    $sharedStrings[] = $stringValue;
                }

                $cellRef = $this->columnLetter($columnIndex).($rowNumber + 1);
                $cellsXml .= sprintf(
                    '<c r="%s" t="s"><v>%d</v></c>',
                    $cellRef,
                    $sharedIndex[$stringValue]
                );
            }

            $sheetRowsXml .= sprintf('<row r="%d">%s</row>', $rowNumber + 1, $cellsXml);
        }

        $sharedXmlParts = array_map(
            fn (string $value) => '<si><t>'.htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</t></si>',
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
        $zip = new ZipArchive;
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
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
