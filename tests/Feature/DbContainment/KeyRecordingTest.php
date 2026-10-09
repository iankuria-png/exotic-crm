<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbScanFinding;
use App\Services\DbScanner\Engine\PassController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class KeyRecordingTest extends TestCase
{
    use BuildsWordPressFixture, RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
        parent::tearDown();
    }

    public function test_duplicate_raw_name_group_survives_recording_and_recurrence_keeps_history(): void
    {
        Queue::fake();
        $this->bootScanner();
        config(['db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        foreach (['siteurl' => 'https://recording.test', 'home' => 'https://recording.test', 'wp_user_roles' => serialize(['subscriber' => ['capabilities' => ['read' => true]]])] as $key => $value) {
            $this->wpOption($pdo, $key, $value);
        }
        $uid = $this->wpUser($pdo, 'member', 'member@recording.test', '2020-01-01 00:00:00', ['subscriber' => true]);
        $keys = [['name' => 'auto-bootstrap', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'password' => 'never-recorded-one', 'last_ip' => '65.98.29.11'], ['name' => 'auto-bootstrap', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'password' => 'never-recorded-two', 'last_ip' => '192.0.2.9']];
        $q = $pdo->prepare('INSERT INTO wp_usermeta(user_id,meta_key,meta_value) VALUES(?,?,?)');
        $q->execute([$uid, '_application_passwords', serialize($keys)]);
        $platform = $this->marketPlatform('Recording', 'recording.test');
        $this->connectFixture($platform, $this->fixturePath($pdo));
        app(PassController::class)->start([$platform->id], 'quick', 'manual');
        $this->drain();
        $findings = DbScanFinding::query()->where('rule_key', 'access.application_passwords')->get();
        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $binding = $finding->evidence['details']['key_binding'];
        $this->assertTrue($binding['complete']);
        $this->assertCount(2, $binding['digests']);
        $this->assertSame(2, $binding['count']);
        $this->assertStringNotContainsString('never-recorded', json_encode($finding));
        $this->assertStringNotContainsString($keys[0]['uuid'], json_encode($finding));
        $finding->update(['status' => 'contained']);
        $q = $pdo->prepare('UPDATE wp_usermeta SET meta_value=? WHERE meta_key=?');
        $q->execute([serialize(array_reverse($keys)), '_application_passwords']);
        app(PassController::class)->start([$platform->id], 'quick', 'manual');
        $this->drain();
        $finding->refresh();
        $this->assertSame('open', $finding->status);
        $this->assertSame($binding, $finding->evidence['details']['key_binding']);
        $this->assertTrue($finding->events()->where('type', 'reopened')->exists());
    }

    public function test_display_collision_and_duplicate_uuid_bindings_are_incomplete(): void
    {
        config(['db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $binder = new \App\Services\DbScanner\KeyObservationBinder;
        $keys = [['name' => 'a  b', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1'], ['name' => 'a b', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2']];
        $groups = $binder->group($keys, ['site', 'db', 'wp_'], 1, 2, []);
        $this->assertCount(2, $groups);
        $this->assertFalse($groups[0]['key_binding']['complete']);
        $this->assertFalse($groups[1]['key_binding']['complete']);
        $keys[1]['uuid'] = $keys[0]['uuid'];
        $groups = $binder->group($keys, ['site', 'db', 'wp_'], 1, 2, []);
        $this->assertFalse($groups[0]['key_binding']['complete']);
    }
}
