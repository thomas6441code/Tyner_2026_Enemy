<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::with(['department', 'workSchedule', 'workLocation', 'user'])
            ->orderBy('last_name')
            ->paginate(15)
            ->through(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'fullName' => $employee->fullName(),
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'phone' => $employee->phone,
                'hire_date' => $employee->hire_date?->format('Y-m-d'),
                'status' => $employee->status,
                'department_id' => $employee->department_id,
                'work_schedule_id' => $employee->work_schedule_id,
                'work_location_id' => $employee->work_location_id,
                'user_id' => $employee->user_id,
                'department' => $employee->department ? ['name' => $employee->department->name] : null,
                'workSchedule' => $employee->workSchedule ? ['name' => $employee->workSchedule->name] : null,
                'workLocation' => $employee->workLocation ? ['name' => $employee->workLocation->name] : null,
                'user' => $employee->user ? ['email' => $employee->user->email] : null,
            ]);

        $total = Employee::count();
        $active = Employee::where('status', 'active')->count();

        return Inertia::render('employees/index', $this->formData() + [
            'employees' => $employees,
            'stats' => [
                'total' => $total,
                'active' => $active,
                'inactive' => max($total - $active, 0),
                'unlinked' => Employee::whereNull('user_id')->count(),
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
        $this->authorize('create', Employee::class);

        $validated = $this->validateEmployee($request);

        Employee::create($validated);

        return redirect()->route('employees.index')->with('status', 'Employee created.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $validated = $this->validateEmployee($request, $employee);

        $employee->update($validated);

        return redirect()->route('employees.index')->with('status', 'Employee updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return redirect()->route('employees.index')->with('status', 'Employee deleted.');
    }

    /**
     * Shared validation rules for store/update.
     */
    private function validateEmployee(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'employee_code' => ['required', 'string', 'max:50', 'unique:employees,employee_code,'.($employee?->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,inactive'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'work_schedule_id' => ['nullable', 'exists:work_schedules,id'],
            // Nullable: an employee with no location of their own inherits their department's.
            'work_location_id' => ['nullable', 'exists:work_locations,id'],
            'user_id' => [
                'nullable',
                'exists:users,id',
                'unique:employees,user_id,'.($employee?->id),
            ],
        ]);
    }

    /**
     * Reference data shared by the create/edit forms.
     */
    private function formData(?Employee $employee = null): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'workSchedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
            'workLocations' => WorkLocation::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'unlinkedUsers' => User::whereDoesntHave('employee')
                ->orWhere('id', $employee?->user_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ];
    }

    /**
     * Action permission flags for the index page (role-scalar, not per-row).
     */
    private function actions(Request $request): array
    {
        return [
            'create' => $request->user()->can('create', Employee::class),
            'update' => $request->user()->can('update', new Employee),
            'delete' => $request->user()->can('delete', new Employee),
        ];
    }
}
