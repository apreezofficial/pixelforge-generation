<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Balance;
use App\Services\Pricing;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Generation $g) => $this->present($g));

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
            'maxReferenceImages' => config('pixelforge.max_reference_images'),
        ]);
    }

    /**
     * Upfront estimate, computed server-side so the UI and the stored value agree.
     */
    public function estimate(Request $request, Pricing $pricing)
    {
        $data = $this->validated($request);
        $estimate = $pricing->estimate(
            $data['prompt'],
            $data['size'],
            $data['quality'],
            count($data['reference_images'] ?? []),
        );

        return response()->json([
            'charge_nano' => $estimate['charge_nano'],
            'charge_display' => Money::display($estimate['charge_nano']),
            'charge_usd' => Money::toUsdString($estimate['charge_nano']),
            'breakdown' => [
                'text_tokens' => $estimate['text_tokens'],
                'image_tokens' => $estimate['image_tokens'],
                'output_tokens' => $estimate['output_tokens'],
                'reasoning_tokens' => $estimate['reasoning_tokens'],
            ],
        ]);
    }

    public function store(Request $request, Pricing $pricing, Balance $balance): RedirectResponse
    {
        $data = $this->validated($request);
        $references = array_values($data['reference_images'] ?? []);
        $estimate = $pricing->estimate($data['prompt'], $data['size'], $data['quality'], count($references));

        $generation = DB::transaction(function () use ($request, $data, $references, $estimate, $balance) {
            // Lock the user row so two simultaneous requests cannot both spend the same credits.
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if ($balance->available($user) < $estimate['charge_nano']) {
                return null;
            }

            return $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $references,
                'status' => Generation::SUBMITTING,
                'estimated_nano' => $estimate['charge_nano'],
            ]);
        });

        if ($generation === null) {
            throw ValidationException::withMessages([
                'prompt' => 'Not enough credits for this generation. Buy more credits and try again.',
            ]);
        }

        SubmitGeneration::dispatch($generation->id);

        return redirect()->route('generations.index');
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:'.implode(',', self::SIZES)],
            'quality' => ['required', 'in:'.implode(',', self::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:'.config('pixelforge.max_reference_images')],
            'reference_images.*' => ['url:http,https', 'max:2048'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(Generation $g): array
    {
        return [
            'id' => $g->id,
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'status' => $g->status,
            'error_message' => $g->error_message,
            'estimated' => Money::display($g->estimated_nano),
            'charged' => $g->charge_nano === null ? null : Money::display($g->charge_nano),
            'charged_usd' => $g->charge_nano === null ? null : Money::toUsdString($g->charge_nano),
            'image_url' => $g->image_url,
            'image_expires_at' => $g->image_url_expires_at?->toIso8601String(),
            'created_at' => $g->created_at->toIso8601String(),
        ];
    }
}
