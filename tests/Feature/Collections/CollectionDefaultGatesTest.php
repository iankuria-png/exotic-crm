<?php

namespace Tests\Feature\Collections;

use Tests\TestCase;

class CollectionDefaultGatesTest extends TestCase
{
    public function test_capture_workspace_and_every_production_entitlement_gate_default_off(): void
    {
        $this->assertFalse(config('collection.capture_enabled'));
        $this->assertFalse(config('collection.workspace_enabled'));
        foreach (['entitlement_platforms', 'automatic_accounts', 'verified_writer_platforms', 'verified_correction_platforms'] as $gate) {
            $this->assertSame([], config('collection.'.$gate));
        }
        $this->assertSame('full_purchased', config('collection.correction_duration_policy'));
    }
}
