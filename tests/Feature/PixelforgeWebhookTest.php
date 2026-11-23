<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the tolerance so a relaxed local .env value cannot change what the tests assert.
        config(['pixelforge.webhook_secret' => self::SECRET, 'pixelforge.webhook_tolerance' => 300]);
    }

    private function generation(array $overrides = []): Generation
    {
        return Generation::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'uuid' => (string) Str::uuid(),
            'pixelforge_id' => 'gen_'.Str::random(8),
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => Generation::QUEUED,
            'estimated_nano' => 1,
        ], $overrides));
    }

    private function succeededObject(Generation $g): array
    {
        return [
            'id' => $g->pixelforge_id,
            'object' => 'generation',
            'status' => 'succeeded',
            'image_url' => 'https://example.test/img.png',
            'image_url_expires_at' => '2026-11-24T00:00:00Z',
            'completed_at' => '2026-11-23T00:00:00Z',
            'usage' => [
                'input_text_tokens' => 41,
                'input_image_tokens' => 0,
                'output_tokens' => 3300,
                'reasoning_tokens' => 2950,
                'total_tokens' => 6291,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ];
    }

    /** @return array{0:string,1:string} body and signature header */
    private function signed(array $event, ?int $timestamp = null): array
    {
        $body = json_encode($event);
        $t = (string) ($timestamp ?? time());
        $sig = hash_hmac('sha256', $t.'.'.$body, self::SECRET);

        return [$body, "t={$t},v1={$sig}"];
    }

    private function deliver(array $event, ?int $timestamp = null)
    {
        [$body, $header] = $this->signed($event, $timestamp);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE-SIGNATURE' => $header,
        ], $body);
    }

    public function test_success_is_charged_exactly_once_even_when_delivered_twice(): void
    {
        $g = $this->generation();
        $event = ['id' => 'evt_1', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeededObject($g)]];

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        $g->refresh();
        $this->assertSame(Generation::SUCCEEDED, $g->status);
        $this->assertSame(169080000, $g->provider_cost_nano);
        $this->assertSame(211350000, $g->charge_nano); // ceil(169080000 * 1.25)
        $this->assertSame(1, Charge::query()->where('generation_id', $g->id)->count());
    }

    public function test_out_of_order_processing_after_success_does_not_regress(): void
    {
        $g = $this->generation();
        $succeeded = ['id' => 'evt_2', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeededObject($g)]];
        $processing = ['id' => 'evt_3', 'object' => 'event', 'type' => 'generation.processing', 'data' => ['object' => ['id' => $g->pixelforge_id, 'status' => 'processing']]];

        $this->deliver($succeeded)->assertOk();
        $this->deliver($processing)->assertOk();

        $this->assertSame(Generation::SUCCEEDED, $g->refresh()->status);
    }

    public function test_failed_generation_is_free(): void
    {
        $g = $this->generation();
        $failed = ['id' => 'evt_4', 'object' => 'event', 'type' => 'generation.failed', 'data' => ['object' => [
            'id' => $g->pixelforge_id,
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'nope'],
        ]]];

        $this->deliver($failed)->assertOk();

        $this->assertSame(Generation::FAILED, $g->refresh()->status);
        $this->assertNull($g->charge_nano);
        $this->assertSame(0, Charge::query()->count());
    }

    public function test_bad_signature_is_rejected(): void
    {
        $g = $this->generation();
        $event = ['id' => 'evt_5', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeededObject($g)]];
        $body = json_encode($event);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE-SIGNATURE' => 't='.time().',v1=deadbeef',
        ], $body)->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $g->refresh()->status);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $g = $this->generation();
        $event = ['id' => 'evt_6', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $this->succeededObject($g)]];

        $this->deliver($event, time() - 3600)->assertStatus(400);
    }

    public function test_unknown_generation_asks_for_retry(): void
    {
        $event = ['id' => 'evt_7', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => ['id' => 'gen_unknown', 'status' => 'succeeded']]];

        $this->deliver($event)->assertStatus(503);
        $this->assertDatabaseMissing('webhook_events', ['event_id' => 'evt_7']);
    }
}
