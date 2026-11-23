<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationUpdater;
use App\Services\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): Generation
    {
        return Generation::query()->create([
            'user_id' => User::factory()->create()->id,
            'uuid' => (string) Str::uuid(),
            'prompt' => 'a fox',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => Generation::SUBMITTING,
            'estimated_nano' => 1,
        ]);
    }

    public function test_accepted_request_stores_pixelforge_id_and_queues(): void
    {
        Http::fake(['*/generations' => Http::response(['id' => 'gen_abc', 'object' => 'generation', 'status' => 'queued'], 202)]);
        $g = $this->pending();

        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $g->refresh();
        $this->assertSame('gen_abc', $g->pixelforge_id);
        $this->assertSame(Generation::QUEUED, $g->status);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $g->uuid));
    }

    public function test_blocked_prompt_fails_without_charge(): void
    {
        Http::fake(['*/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'blocked']], 400)]);
        $g = $this->pending();

        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $g->refresh();
        $this->assertSame(Generation::FAILED, $g->status);
        $this->assertSame('content_policy_violation', $g->error_code);
        $this->assertNull($g->charge_nano);
    }

    public function test_overload_is_retried_later_with_retry_after(): void
    {
        Queue::fake();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])]);
        $g = $this->pending();

        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $this->assertSame(Generation::SUBMITTING, $g->refresh()->status);
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $g->id);
    }
}
