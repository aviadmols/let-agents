<?php

namespace App\Modules\Analytics\Tests;

use App\Modules\Analytics\Support\BeaconSchema;
use Tests\TestCase;

/**
 * What shoppers do with a tag in the tag bank, and with the module's search field, is a valid
 * event like any other section's, so it reaches the learning instead of being turned away.
 */
final class TagEventsTest extends TestCase
{
    public function test_tag_and_field_events_pass_the_event_spec(): void
    {
        foreach ([['tag:4f1c2a9b7d3e', 'tag'], ['find', 'find']] as [$candidate, $model]) {
            $beacon = [
                'v' => 1, 'shop' => '01k00000000000000000000000', 'vid' => 'anon-visitor1234567890abcd', 'session' => 'session12345',
                'sent_at' => (int) (microtime(true) * 1000), 'holdout' => false,
                'events' => [[
                    'id' => 'evt'.bin2hex(random_bytes(8)),
                    'type' => 'open',
                    'ts' => (int) (microtime(true) * 1000),
                    'page' => ['type' => 'product', 'path' => '/product/deck/', 'product_id' => '201'],
                    'candidate' => ['id' => $candidate, 'version' => 1, 'model' => $model, 'slot' => 'chip_1'],
                    'bank_version' => 1,
                    'eligible' => [$candidate],
                    'bucket' => 'none',
                ]],
            ];

            $problems = app(BeaconSchema::class)->problems(json_decode((string) json_encode($beacon)));

            $this->assertSame([], $problems, "{$model} events are turned away: ".implode('; ', $problems));
        }
    }
}
