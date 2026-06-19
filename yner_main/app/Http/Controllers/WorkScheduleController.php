<?php

namespace App\Http\Controllers;

use App\Models\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class WorkScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WorkSchedule::class);

        $workSchedules = WorkSchedule::withCount('employees')->orderBy('name')->paginate(15)
            ->through(fn (WorkSchedule $workSchedule) => [
                'id' => $workSchedule->id,
                'name' => $workSchedule->name,
                'start_time' => Carbon::parse($workSchedule->start_time)->format('H:i'),
                'end_time' => Carbon::parse($workSchedule->end_time)->format('H:i'),
                'grace_period_minutes' => $workSchedule->grace_period_minutes,
                'employees_count' => $workSchedule->employees_count,
            ]);

        return Inertia::render('work-schedules/index', [
            'workSchedules' => $workSchedules,
            'actions' => $this->actions($request),
            'status' => session('status'),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $this->authorize('create', WorkSchedule::class);

        return Inertia::render('work-schedules/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WorkSchedule::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:work_schedules,name'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        WorkSchedule::create($validated);

        return redirect()->route('work-schedules.index')->with('status', 'Work schedule created.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WorkSchedule $workSchedule): Response
    {
        $this->authorize('update', $workSchedule);

        return Inertia::render('work-schedules/edit', [
            'workSchedule' => [
                'id' => $workSchedule->id,
                'name' => $workSchedule->name,
                'start_time' => Carbon::parse($workSchedule->start_time)->format('H:i'),
                'end_time' => Carbon::parse($workSchedule->end_time)->format('H:i'),
                'grace_period_minutes' => $workSchedule->grace_period_minutes,
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkSchedule $workSchedule): RedirectResponse
    {
        $this->authorize('update', $workSchedule);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:work_schedules,name,'.$workSchedule->id],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        $workSchedule->update($validated);

        return redirect()->route('work-schedules.index')->with('status', 'Work schedule updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkSchedule $workSchedule): RedirectResponse
    {
        $this->authorize('delete', $workSchedule);

        $workSchedule->delete();

        return redirect()->route('work-schedules.index')->with('status', 'Work schedule deleted.');
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', WorkSchedule::class),
            'update' => $request->user()->can('update', new WorkSchedule),
            'delete' => $request->user()->can('delete', new WorkSchedule),
        ];
    }
}
