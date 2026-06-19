<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasRole(RoleName::Admin->value)) {
            return Inertia::render('dashboard/admin', [
                'departmentCount' => Department::count(),
                'employeeCount' => Employee::count(),
                'activeEmployeeCount' => Employee::where('status', 'active')->count(),
                'unlinkedEmployeeCount' => Employee::whereNull('user_id')->count(),
            ]);
        }

        if ($user->hasRole(RoleName::HrOfficer->value)) {
            return Inertia::render('dashboard/hr', [
                'employeeCount' => Employee::count(),
                'activeEmployeeCount' => Employee::where('status', 'active')->count(),
                'departments' => Department::withCount('employees')->orderBy('name')->get(),
            ]);
        }

        return Inertia::render('dashboard/employee', [
            'employee' => $user->employee()->with(['department', 'workSchedule'])->first(),
        ]);
    }
}
