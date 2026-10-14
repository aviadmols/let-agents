<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Support\Drivers\ReplicateImages;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pictures from Replicate: the prediction waited for in the request, polled when it takes longer,
 * the picture fetched, and each model given its pictures under the name it expects.
 */
final class ReplicateImagesTest extends TestCase
{
    public function test_a_picture_is_made_in_one_request_with_the_pictures_as_the_model_names_them(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/google/nano-banana/predictions' => Http::response(['id' => 'p1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.jpg', 'metrics' => ['predict_time' => 7.2]]),
            'replicate.delivery/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $reply = (new ReplicateImages)->generate('r8_key', 'google/nano-banana', 'Put the ring on.', [['mime' => 'image/jpeg', 'bytes' => 'a'], ['mime' => 'image/jpeg', 'bytes' => 'b']]);

        $this->assertSame('JPEGBYTES', $reply->bytes);
        $this->assertSame(7.2, $reply->seconds);
        $this->assertSame('p1', $reply->predictionId);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/predictions')
            && $r->hasHeader('Authorization', 'Bearer r8_key') && $r->hasHeader('Prefer', 'wait=60')
            && $r['input']['image_input'] === ['data:image/jpeg;base64,'.base64_encode('a'), 'data:image/jpeg;base64,'.base64_encode('b')]
            && $r['input']['prompt'] === 'Put the ring on.'
            // The shopper's photo is edited, not redrawn in another shape.
            && $r['input']['aspect_ratio'] === 'match_input_image');
    }

    public function test_a_slow_prediction_is_asked_again_and_a_single_picture_model_gets_one(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response(['id' => 'p2', 'status' => 'processing', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p2']]),
            'api.replicate.com/v1/predictions/p2' => Http::sequence()
                ->push(['id' => 'p2', 'status' => 'processing', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p2']])
                ->push(['id' => 'p2', 'status' => 'succeeded', 'output' => ['https://replicate.delivery/two.jpg']]),
            'replicate.delivery/*' => Http::response('TWO', 200),
        ]);

        $reply = (new ReplicateImages)->generate('r8_key', 'black-forest-labs/flux-kontext-pro', 'x', [['mime' => 'image/jpeg', 'bytes' => 'a']]);

        $this->assertSame('TWO', $reply->bytes);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'flux-kontext-pro/predictions') && $r['input']['input_image'] === 'data:image/jpeg;base64,'.base64_encode('a'));
    }

    public function test_a_failed_prediction_or_a_bad_model_name_fails_the_call(): void
    {
        Http::fake(['api.replicate.com/*' => Http::response(['id' => 'p3', 'status' => 'failed', 'error' => 'NSFW'])]);

        try {
            (new ReplicateImages)->generate('r8_key', 'google/nano-banana', 'x', []);
            $this->fail('a failed prediction is a failed call');
        } catch (ModelCallFailed $e) {
            $this->assertSame(ModelCallFailed::PROVIDER_ERROR, $e->reason);
        }

        $this->expectException(ModelCallFailed::class);
        (new ReplicateImages)->generate('r8_key', '../../etc', 'x', []);
    }
}
