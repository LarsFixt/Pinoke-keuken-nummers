<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen' => ['required', 'in:wedstrijdschema,kitchen'],
        ]);

        $screen = $validated['screen'];
        $today = CarbonImmutable::today();

        $sponsors = Sponsor::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with([
                'adAssets' => function ($query) use ($screen): void {
                    $query
                        ->whereIn('target_screen', ['both', $screen])
                        ->orderByDesc('frequency_weight');
                },
            ])
            ->get();

        $playlist = $this->buildWeightedPlaylist($sponsors);

        return response()->json(
            $playlist
                ->shuffle()
                ->values()
                ->map(function (array $item): array {
                    return [
                        'sponsor_name' => $item['sponsor_name'],
                        'title' => $item['title'],
                        'call_to_action' => $item['call_to_action'],
                        'image_url' => asset('storage/'.$item['file_path']),
                        'duration_seconds' => $item['duration_seconds'],
                    ];
                })
                ->all()
        );
    }

    /**
     * @param  Collection<int, Sponsor>  $sponsors
     * @return Collection<int, array{
     *     sponsor_name: string,
     *     title: ?string,
     *     call_to_action: ?string,
     *     file_path: string,
     *     duration_seconds: int
     * }>
     */
    private function buildWeightedPlaylist(Collection $sponsors): Collection
    {
        return $sponsors->flatMap(function (Sponsor $sponsor): Collection {
            return $sponsor->adAssets->flatMap(function ($asset) use ($sponsor): Collection {
                $item = [
                    'sponsor_name' => $sponsor->name,
                    'title' => $sponsor->title,
                    'call_to_action' => $sponsor->call_to_action,
                    'file_path' => $asset->file_path,
                    'duration_seconds' => $asset->duration_seconds,
                ];

                return collect()->times($asset->frequency_weight, static fn (): array => $item);
            });
        });
    }
}
