<?php

namespace Tests\Feature\Collections;

use Tests\TestCase;

class CollectionDefaultGatesTest extends TestCase
{
    public function test_capture_workspace_default_off_and_entitlement_gates_release_only_tz(): void
    {
        $this->assertFalse(config('collection.capture_enabled'));
        $this->assertFalse(config('collection.workspace_enabled'));
        foreach (['entitlement_platforms', 'verified_writer_platforms', 'verified_correction_platforms'] as $gate) {
            $this->assertSame([6, 2], config('collection.'.$gate), $gate);
        }
        $this->assertSame(['c12d64b4-d300-4927-bea1-94301e28c1ef'], config('collection.automatic_accounts'));
        $this->assertSame([6 => 2], config('collection.subsidiary_platforms'));
        $this->assertSame('full_purchased', config('collection.correction_duration_policy'));
    }
}
