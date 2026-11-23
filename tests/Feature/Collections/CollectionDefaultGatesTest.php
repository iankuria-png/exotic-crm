<?php

namespace Tests\Feature\Collections;

use Tests\TestCase;

class CollectionDefaultGatesTest extends TestCase
{
    public function test_capture_defaults_off_and_kenya_assisted_release_does_not_enable_automatic_activation(): void
    {
        $this->assertFalse(config('collection.capture_enabled'));
        $this->assertFalse(config('collection.workspace_enabled'));
        foreach (['entitlement_platforms', 'verified_writer_platforms', 'verified_correction_platforms'] as $gate) {
            $this->assertSame([6, 2, 3, 1], config('collection.'.$gate), $gate);
        }
        $this->assertSame(['c12d64b4-d300-4927-bea1-94301e28c1ef', '5c1b4a9f-b95e-4e64-bbf9-76ccc5c713d6'], config('collection.automatic_accounts'));
        $this->assertSame([6 => 2], config('collection.subsidiary_platforms'));
        // Staff Match & activate includes the independently verified Kenya till. Automatic release remains off.
        $this->assertSame(['c12d64b4-d300-4927-bea1-94301e28c1ef', '5c1b4a9f-b95e-4e64-bbf9-76ccc5c713d6', '8d5c1032-4ed1-45e0-a842-268491000001'], config('collection_sales.verified_accounts'));
        $this->assertSame([6, 3, 1], config('collection_sales.verified_platforms'));
        $this->assertFalse(config('collection_kopokopo.automatic_rollout_enabled'));
        $this->assertNotContains(config('collection_kopokopo.account_id'), config('collection.automatic_accounts'));
        $this->assertSame('full_purchased', config('collection.correction_duration_policy'));
    }
}
