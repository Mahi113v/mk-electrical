<?php

namespace App\Http\Controllers;

use App\Support\Tenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function ok($data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function fail(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['success' => false, 'message' => $message], $extra), $status);
    }

    /** Never leak internal error text in production. */
    protected function serverError(\Throwable $e, string $message): JsonResponse
    {
        report($e);

        return $this->fail($message, 500, config('app.debug') ? ['error' => $e->getMessage()] : []);
    }

    /** Optional ?from=YYYY-MM-DD&to=YYYY-MM-DD of a register / list. Returns [from|null, to|null]. */
    protected function range(\Illuminate\Http\Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [$data['from'] ?? null, $data['to'] ?? null];
    }

    protected function tenant(): Tenant
    {
        return app(Tenant::class);
    }

    /** A document date must fall inside the selected account year. Empty = today (or the year's last day). */
    protected function dateInYear(?string $date, string $field = 'date'): string
    {
        $year = $this->tenant()->year;
        $date = $date ?: ($year->contains(now()->toDateString()) ? now()->toDateString() : $year->end_date->toDateString());

        if (!$year->contains($date)) {
            throw ValidationException::withMessages([
                $field => ["Date must be inside the account year {$year->label} ({$year->start_date->format('d/m/Y')} - {$year->end_date->format('d/m/Y')})."],
            ]);
        }

        return $date;
    }
}
