<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);

        $departments = Department::withCount('employees')->orderBy('name')->paginate(15);

        $totalDepartments = Department::count();
        $assigned = Employee::whereNotNull('department_id')->count();

        return Inertia::render('departments/index', [
            'departments' => $departments,
            'stats' => [
                'departments' => $totalDepartments,
                'employees' => $assigned,
                'avgPerDepartment' => $totalDepartments > 0 ? round($assigned / $totalDepartments, 1) : 0,
                'empty' => Department::doesntHave('employees')->count(),
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
        $this->authorize('create', Department::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name'],
            'description' => ['nullable', 'string'],
        ]);

        Department::create($validated);

        return redirect()->route('departments.index')->with('status', 'Department created.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name,'.$department->id],
            'description' => ['nullable', 'string'],
        ]);

        $department->update($validated);

        return redirect()->route('departments.index')->with('status', 'Department updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('delete', $department);

        $department->delete();

        return redirect()->route('departments.index')->with('status', 'Department deleted.');
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', Department::class),
            'update' => $request->user()->can('update', new Department),
            'delete' => $request->user()->can('delete', new Department),
        ];
    }
}
