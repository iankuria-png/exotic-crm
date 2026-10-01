<?php

namespace Tests\Unit\DbScanner;

use App\Services\DbScanner\Engine\ScheduleWindow;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\Malware\BoundedDecoder;
use App\Services\DbScanner\Malware\SerializedParser;
use App\Services\DbScanner\Reader\QueryCompiler;
use App\Services\DbScanner\Reader\ReaderException;
use App\Services\DbScanner\Surfaces\SecretPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DbScannerSafetyTest extends TestCase
{
    private function decoder(array $limits = []): BoundedDecoder
    {
        return new BoundedDecoder($limits + [
            'max_input_bytes' => 65536, 'max_output_bytes' => 262144, 'max_expansion_ratio' => 16,
            'max_depth' => 3, 'max_branches' => 16, 'max_nodes' => 10000, 'cpu_budget_ms' => 200,
        ]);
    }

    public function test_decoder_reveals_nested_base64_and_records_the_chain(): void
    {
        $inner = base64_encode('<script>eval(atob("x"))</script>');
        $outer = base64_encode('var p="'.$inner.'";');
        $result = $this->decoder()->expand('payload="'.$outer.'"');

        $chains = array_map(fn ($l) => implode('>', $l['chain']), $result->layers);
        $texts = implode("\n", array_column($result->layers, 'text'));
        $this->assertContains('base64>base64', $chains);
        $this->assertStringContainsString('<script>eval(', $texts);
    }

    public function test_decoder_handles_gzip_with_an_output_limit(): void
    {
        $payload = base64_encode(gzencode('<?php system($_GET["c"]); ?>'));
        $result = $this->decoder()->expand('x="'.$payload.'"');
        $this->assertStringContainsString('system($_GET', implode('', array_column($result->layers, 'text')));

        $bomb = base64_encode(gzencode(str_repeat('A', 2_000_000)));
        $capped = $this->decoder(['max_output_bytes' => 4096])->expand('x="'.$bomb.'"');
        $this->assertTrue($capped->capped(), 'A decompression bomb is capped, not expanded.');
        $this->assertLessThanOrEqual(4096, array_sum(array_map(fn ($l) => strlen($l['text']), $capped->layers)));
    }

    public function test_decoder_ignores_image_data_uris(): void
    {
        $result = $this->decoder()->expand('<img src="data:image/png;base64,'.base64_encode(random_bytes(300)).'">');
        $this->assertSame([], array_filter($result->layers, fn ($l) => in_array('base64', $l['chain'], true)));
    }

    public function test_decoder_depth_is_bounded(): void
    {
        $value = 'echo 1;';
        for ($i = 0; $i < 6; $i++) {
            $value = base64_encode($value.str_repeat(' ', 40));
        }
        $result = $this->decoder(['max_depth' => 2])->expand('"'.$value.'"');
        $this->assertTrue($result->capped());
        $this->assertLessThanOrEqual(2, max(array_map(fn ($l) => count($l['chain']), $result->layers ?: [['chain' => []]])));
    }

    public function test_serialized_parser_never_instantiates_objects(): void
    {
        $parser = new SerializedParser;
        $value = 'a:2:{s:4:"safe";s:2:"ok";s:3:"obj";O:8:"Exploit1":1:{s:3:"cmd";s:2:"id";}}';
        $parsed = $parser->parse($value);

        $this->assertSame('ok', $parsed['safe']);
        $this->assertSame('Exploit1', $parsed['obj']['__class__']);
        $this->assertSame(['Exploit1'], $parser->classes);
        $this->assertFalse(class_exists('Exploit1', false));
    }

    public function test_serialized_parser_rejects_malformed_and_oversized_input(): void
    {
        $parser = new SerializedParser(maxNodes: 50);
        $this->assertNull($parser->parse('a:1:{s:999:"short";}'));
        $this->assertNotNull($parser->error);
        $this->assertNull($parser->parse(serialize(range(1, 100))));
        $this->assertSame('nodes', $parser->error);
    }

    public function test_evidence_redacts_credentials_tokens_queries_emails_and_phones(): void
    {
        $text = 'see https://user:pa55@evil.test/p?token=abc123&uid=9 mail admin@company.com call +254 712 345 678 key sk_live_abcdefghijklmnop jwt eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2QT4';
        $clean = (new EvidenceSanitizer(500))->clean($text);

        $this->assertStringNotContainsString('pa55', $clean);
        $this->assertStringNotContainsString('abc123', $clean);
        $this->assertStringContainsString('token=[redacted]', $clean);
        $this->assertStringNotContainsString('admin@company.com', $clean);
        $this->assertStringContainsString('a***@company.com', $clean);
        $this->assertStringNotContainsString('712 345', $clean);
        $this->assertStringNotContainsString('sk_live_abcdefghijklmnop', $clean);
        $this->assertStringNotContainsString('eyJzdWIiOiIxMjM0', $clean);
    }

    public function test_evidence_is_capped_and_csv_formulas_are_neutralised(): void
    {
        $this->assertLessThanOrEqual(201, mb_strlen((new EvidenceSanitizer)->clean(str_repeat('x', 5000))));
        $this->assertSame("'=cmd|' /C calc'!A0", EvidenceSanitizer::csvSafe("=cmd|' /C calc'!A0"));
        $this->assertSame("'@SUM(1)", EvidenceSanitizer::csvSafe('@SUM(1)'));
        $this->assertSame('plain', EvidenceSanitizer::csvSafe('plain'));
    }

    public function test_secret_policy_excludes_secret_names_but_not_ordinary_options(): void
    {
        $policy = new SecretPolicy;
        foreach (['smtp_password', 'mailserver_pass', 'wp_mail_smtp', 'stripe_api_key', 'exotic_crm_sync_shared_key', 'session_tokens', '_application_passwords', 'nonce_salt', 'client_secret'] as $name) {
            $this->assertTrue($policy->isProtected($name), $name);
        }
        foreach (['siteurl', 'widget_text', 'monetize_passes', 'active_plugins', 'compass_settings', '_core_version_check_hash'] as $name) {
            $this->assertFalse($policy->isProtected($name), $name);
        }
    }

    public function test_query_compiler_rejects_unknown_identifiers_and_injection(): void
    {
        $compiler = new QueryCompiler('mysql');
        $compiler->bindSchema(['wp_options' => ['option_id' => 'bigint', 'option_name' => 'varchar', 'option_value' => 'longtext']]);

        $ok = $compiler->enumerate('wp_options', 'option_id', ['option_name'], ['option_value'], 0, 100, [['option_name', 'like', 'x%']]);
        $this->assertStringStartsWith('SELECT', $ok->sql);
        $this->assertStringNotContainsString(';', $ok->sql);
        $this->assertStringContainsString('OCTET_LENGTH', $ok->sql);

        $attempts = [
            fn () => $compiler->maxKey('wp_users', 'ID'),
            fn () => $compiler->maxKey('wp_options`; DROP TABLE wp_options; --', 'option_id'),
            fn () => $compiler->enumerate('wp_options', 'option_id', ['user_pass'], [], 0, 1, []),
            fn () => $compiler->enumerate('wp_options', 'option_id', [], [], 0, 1, [['option_name); DELETE FROM x; --', '=', 1]]),
            fn () => $compiler->enumerate('wp_options', 'option_id', [], [], 0, 1, [['option_name', 'regexp', '.*']]),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail('Attempt '.$i.' was not rejected.');
            } catch (ReaderException $e) {
                $this->assertSame(ReaderException::REJECTED_TEMPLATE, $e->errorCode);
            }
        }
    }

    public function test_fetch_slices_by_bytes_not_characters(): void
    {
        $compiler = new QueryCompiler('mysql');
        $compiler->bindSchema(['wp_posts' => ['ID' => 'bigint', 'post_content' => 'longtext']]);
        $sql = $compiler->fetchValues('wp_posts', 'ID', ['post_content'], [1, 2], 65536)->sql;
        $this->assertStringContainsString('SUBSTRING(CAST(`post_content` AS BINARY), 1, 65536)', $sql);
        $this->assertStringNotContainsString('LEFT(', $sql);
    }

    public function test_reader_exceptions_never_carry_driver_messages(): void
    {
        $pdo = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'reader'@'10.0.0.1' (using password: YES)");
        $pdo->errorInfo = ['HY000', 1045, 'Access denied for user reader'];
        $error = ReaderException::fromThrowable($pdo);

        $this->assertSame(ReaderException::ACCESS_DENIED, $error->errorCode);
        $this->assertStringNotContainsString('reader', $error->getMessage());
        $this->assertNull($error->getPrevious());
    }

    public function test_schedule_windows_follow_market_local_time_including_overnight(): void
    {
        $at = CarbonImmutable::parse('2026-10-01 23:30:00', 'UTC'); // 01:30 in Harare
        $this->assertTrue(ScheduleWindow::isOpen(['start' => '01:00', 'end' => '05:30'], 'Africa/Harare', $at));
        $this->assertFalse(ScheduleWindow::isOpen(['start' => '01:00', 'end' => '05:30'], 'UTC', $at));
        $this->assertTrue(ScheduleWindow::isOpen(['start' => '22:00', 'end' => '04:00'], 'UTC', $at));
        $this->assertTrue(ScheduleWindow::isOpen([], 'UTC', $at));
    }
}
