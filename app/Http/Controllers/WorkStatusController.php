<?php

namespace App\Http\Controllers;

use App\Models\WorkStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkStatusController extends Controller
{
    private function rules(): array
    {
        return [
            'work_date'           => ['nullable', 'date'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.work_place'  => ['required', 'string', 'max:255'],
            'items.*.worker_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        try {
            $works = WorkStatus::forTenant()->with(['user:id,name', 'workLists'])
                ->when($request->filled('month') && $request->filled('year'), function ($q) use ($request) {
                    $q->whereMonth('work_date', $request->month)->whereYear('work_date', $request->year);
                })
                ->when($from, fn ($q) => $q->whereDate('work_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to))
                ->orderByDesc('work_date')->orderByDesc('id')->get();

            return $this->ok($works, 'WorkStatus fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'WorkStatus fetch server error');
        }
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(WorkStatus::forTenant()->with(['user:id,name', 'workLists'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $date = $this->dateInYear($data['work_date'] ?? null, 'work_date');

        $work = DB::transaction(function () use ($data, $date, $request) {
            $work = WorkStatus::create(['user_id' => $request->user()->id, 'work_date' => $date]);
            $work->workLists()->createMany($data['items']);

            return $work;
        });

        return $this->ok($work->load('workLists'), 'Work status created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $work = WorkStatus::forTenant()->findOrFail($id);
        $data = $request->validate($this->rules());
        $date = $this->dateInYear($data['work_date'] ?? $work->work_date?->toDateString(), 'work_date');

        DB::transaction(function () use ($work, $data, $date) {
            $work->update(['work_date' => $date]);
            $work->workLists()->delete();
            $work->workLists()->createMany($data['items']);
        });

        return $this->ok($work->fresh('workLists'), 'Work status updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        WorkStatus::forTenant()->findOrFail($id)->delete();   // work_list rows cascade

        return $this->ok(null, 'Work status deleted successfully');
    }
}
