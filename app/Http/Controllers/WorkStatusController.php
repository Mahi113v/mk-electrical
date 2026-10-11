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

    private function canManage(Request $request, WorkStatus $work): bool
    {
        return $request->user()->is_admin || $work->user_id === $request->user()->id;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $works = WorkStatus::with(['user:id,name', 'workLists'])
                ->when($request->filled('month') && $request->filled('year'), function ($q) use ($request) {
                    $q->whereMonth('work_date', $request->month)->whereYear('work_date', $request->year);
                })
                ->orderByDesc('work_date')->orderByDesc('id')->get();

            return $this->ok($works, 'WorkStatus fetched successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'WorkStatus fetch server error');
        }
    }

    // route parameter is {work}; the old code type-hinted $order so binding never matched
    public function show(WorkStatus $work): JsonResponse
    {
        return $this->ok($work->load(['user:id,name', 'workLists']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        try {
            $work = DB::transaction(function () use ($data, $request) {
                $work = WorkStatus::create([
                    'user_id'   => $request->user()->id,
                    'work_date' => $data['work_date'] ?? now()->toDateString(),
                ]);
                $work->workLists()->createMany($data['items']);

                return $work;
            });

            return $this->ok($work->load('workLists'), 'Work status created successfully', 201);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while creating work status');
        }
    }

    public function update(Request $request, WorkStatus $work): JsonResponse
    {
        if (!$this->canManage($request, $work)) {
            return $this->fail('You can only edit your own work status.', 403);
        }

        $data = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($work, $data) {
                $work->update(['work_date' => $data['work_date'] ?? $work->work_date]);
                $work->workLists()->delete();
                $work->workLists()->createMany($data['items']);
            });

            return $this->ok($work->fresh('workLists'), 'Work status updated successfully');
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Server error while updating work status');
        }
    }

    public function destroy(Request $request, WorkStatus $work): JsonResponse
    {
        if (!$this->canManage($request, $work)) {
            return $this->fail('You can only delete your own work status.', 403);
        }

        $work->delete();   // work_list rows cascade

        return $this->ok(null, 'Work status deleted successfully');
    }
}
