<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\WorkLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkLocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WorkLocation::class);

        $workLocations = WorkLocation::withCount(['employees', 'departments'])
            ->orderBy('name')
            ->paginate(15)
            ->through(fn (WorkLocation $workLocation) => [
                'id' => $workLocation->id,
                'name' => $workLocation->name,
                'address' => $workLocation->address,
                'latitude' => (float) $workLocation->latitude,
                'longitude' => (float) $workLocation->longitude,
                'radius_meters' => $workLocation->radius_meters,
                'is_active' => $workLocation->is_active,
                'employees_count' => $workLocation->employees_count,
                'departments_count' => $workLocation->departments_count,
            ]);

        return Inertia::render('work-locations/index', [
            'workLocations' => $workLocations,
            'stats' => [
                'locations' => WorkLocation::count(),
                'active' => WorkLocation::where('is_active', true)->count(),
                'employees' => Employee::whereNotNull('work_location_id')->count(),
                'unassigned' => WorkLocation::doesntHave('employees')->doesntHave('departments')->count(),
            ],
            'actions' => $this->actions($request),
            'status' => session('status'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WorkLocation::class);

        WorkLocation::create($this->validateWorkLocation($request));

        return redirect()->route('work-locations.index')->with('status', 'Work location created.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $this->authorize('update', $workLocation);

        $workLocation->update($this->validateWorkLocation($request, $workLocation));

        return redirect()->route('work-locations.index')->with('status', 'Work location updated.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * The FKs on employees and departments are nullOnDelete, so this does not orphan rows — it
     * leaves the affected people with no resolvable location, and GeofenceService then refuses
     * their mobile check-ins rather than falling back to somewhere wrong.
     */
    public function destroy(WorkLocation $workLocation): RedirectResponse
    {
        $this->authorize('delete', $workLocation);

        $workLocation->delete();

        return redirect()->route('work-locations.index')->with('status', 'Work location deleted.');
    }

    /**
     * Shared validation rules for store/update.
     */
    private function validateWorkLocation(Request $request, ?WorkLocation $workLocation = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('work_locations', 'name')->ignore($workLocation)],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // The 20m floor is not arbitrary: consumer GPS is rarely better than 10-20m even
            // with a clear sky, so a tighter radius is unenforceable and would reject employees
            // who are genuinely standing at the door.
            'radius_meters' => ['required', 'integer', 'min:20', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', WorkLocation::class),
            'update' => $request->user()->can('update', new WorkLocation),
            'delete' => $request->user()->can('delete', new WorkLocation),
        ];
    }
}
