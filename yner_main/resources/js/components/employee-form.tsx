import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Option {
    id: number;
    name: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
}

interface EmployeeFormData {
    employee_code: string;
    first_name: string;
    last_name: string;
    phone: string;
    hire_date: string;
    status: string;
    department_id: string;
    work_schedule_id: string;
    work_location_id: string;
    user_id: string;
}

interface EmployeeFormProps {
    data: EmployeeFormData;
    setData: (key: keyof EmployeeFormData, value: string) => void;
    errors: Partial<Record<keyof EmployeeFormData, string>>;
    departments: Option[];
    workSchedules: Option[];
    workLocations: Option[];
    unlinkedUsers: UserOption[];
}

const NONE = '__none__';

export function EmployeeForm({
    data,
    setData,
    errors,
    departments,
    workSchedules,
    workLocations,
    unlinkedUsers,
}: EmployeeFormProps) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="employee_code">Employee Code</Label>
                    <Input
                        id="employee_code"
                        name="employee_code"
                        value={data.employee_code}
                        className="mt-1"
                        autoFocus
                        onChange={(e) => setData('employee_code', e.target.value)}
                    />
                    <InputError message={errors.employee_code} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="status">Status</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                        <SelectTrigger id="status" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} className="mt-2" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="first_name">First Name</Label>
                    <Input
                        id="first_name"
                        name="first_name"
                        value={data.first_name}
                        className="mt-1"
                        onChange={(e) => setData('first_name', e.target.value)}
                    />
                    <InputError message={errors.first_name} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="last_name">Last Name</Label>
                    <Input
                        id="last_name"
                        name="last_name"
                        value={data.last_name}
                        className="mt-1"
                        onChange={(e) => setData('last_name', e.target.value)}
                    />
                    <InputError message={errors.last_name} className="mt-2" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        value={data.phone}
                        className="mt-1"
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                    <InputError message={errors.phone} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="hire_date">Hire Date</Label>
                    <Input
                        id="hire_date"
                        name="hire_date"
                        type="date"
                        value={data.hire_date}
                        className="mt-1"
                        onChange={(e) => setData('hire_date', e.target.value)}
                    />
                    <InputError message={errors.hire_date} className="mt-2" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="department_id">Department</Label>
                    <Select
                        value={data.department_id || NONE}
                        onValueChange={(value) => setData('department_id', value === NONE ? '' : value)}
                    >
                        <SelectTrigger id="department_id" className="mt-1">
                            <SelectValue placeholder="— None —" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>— None —</SelectItem>
                            {departments.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.department_id} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="work_schedule_id">Work Schedule</Label>
                    <Select
                        value={data.work_schedule_id || NONE}
                        onValueChange={(value) => setData('work_schedule_id', value === NONE ? '' : value)}
                    >
                        <SelectTrigger id="work_schedule_id" className="mt-1">
                            <SelectValue placeholder="— None —" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>— None —</SelectItem>
                            {workSchedules.map((workSchedule) => (
                                <SelectItem key={workSchedule.id} value={String(workSchedule.id)}>
                                    {workSchedule.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.work_schedule_id} className="mt-2" />
                </div>
            </div>

            <div>
                <Label htmlFor="work_location_id">Work Location</Label>
                <Select
                    value={data.work_location_id || NONE}
                    onValueChange={(value) => setData('work_location_id', value === NONE ? '' : value)}
                >
                    <SelectTrigger id="work_location_id" className="mt-1">
                        <SelectValue placeholder="— Inherit from department —" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>— Inherit from department —</SelectItem>
                        {workLocations.map((workLocation) => (
                            <SelectItem key={workLocation.id} value={String(workLocation.id)}>
                                {workLocation.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="mt-1 text-xs text-muted-foreground">
                    Where this employee may check in from a phone. With no location here and none on their
                    department, mobile check-in is refused.
                </p>
                <InputError message={errors.work_location_id} className="mt-2" />
            </div>

            <div>
                <Label htmlFor="user_id">Linked User Account</Label>
                <Select
                    value={data.user_id || NONE}
                    onValueChange={(value) => setData('user_id', value === NONE ? '' : value)}
                >
                    <SelectTrigger id="user_id" className="mt-1">
                        <SelectValue placeholder="— None —" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>— None —</SelectItem>
                        {unlinkedUsers.map((user) => (
                            <SelectItem key={user.id} value={String(user.id)}>
                                {user.name} ({user.email})
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.user_id} className="mt-2" />
            </div>
        </div>
    );
}
