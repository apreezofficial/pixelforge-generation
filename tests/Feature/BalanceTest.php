<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use App\Services\Balance;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['prompt' => 'a fox', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
    }

    public function test_generating_is_blocked_without_credits(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->payload())->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_buying_credits_unblocks_and_holds_the_estimate_while_in_flight(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 5])->assertRedirect();
        $this->assertSame(5 * Money::NANO_PER_USD, app(Balance::class)->available($user));

        $this->actingAs($user)->post('/generations', $this->payload())->assertRedirect('/generations');

        $generation = Generation::query()->firstOrFail();
        $this->assertSame(
            5 * Money::NANO_PER_USD - $generation->estimated_nano,
            app(Balance::class)->available($user),
        );
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_failed_generation_releases_its_hold_and_success_settles_exactly(): void
    {
        $user = User::factory()->create();
        app(Balance::class)->purchase($user, 5 * Money::NANO_PER_USD);

        $make = fn (string $status, array $extra = []) => Generation::query()->create(array_merge([
            'user_id' => $user->id, 'uuid' => (string) Str::uuid(), 'prompt' => 'x', 'size' => '1024x1024',
            'quality' => 'low', 'reference_images' => [], 'status' => $status, 'estimated_nano' => 1_000_000_000,
        ], $extra));

        $failed = $make(Generation::FAILED);
        $this->assertSame(5 * Money::NANO_PER_USD, app(Balance::class)->available($user));

        $ok = $make(Generation::SUCCEEDED, ['charge_nano' => 250_000_000]);
        Charge::query()->create([
            'user_id' => $user->id, 'generation_id' => $ok->id, 'amount_nano' => 250_000_000,
            'provider_cost_nano' => 200_000_000, 'charged_at' => now(),
        ]);

        $this->assertSame(5 * Money::NANO_PER_USD - 250_000_000, app(Balance::class)->available($user));
    }

    public function test_report_is_admin_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/report')->assertForbidden();

        $user->forceFill(['is_admin' => true])->save();
        Http::fake(['*/usage*' => Http::response(['data' => []], 200)]);
        $this->actingAs($user)->get('/admin/report')->assertOk();
    }
}
