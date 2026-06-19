import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface DeviceOption {
    id: number;
    name: string;
    serial: string;
}

interface EmployeeOption {
    id: number;
    fullName: string;
}

interface DeviceEnrollmentFormData {
    biometric_device_id: string;
    employee_id: string;
    device_user_id: string;
}

interface DeviceEnrollmentFormProps {
    data: DeviceEnrollmentFormData;
    setData: (key: keyof DeviceEnrollmentFormData, value: string) => void;
    errors: Partial<Record<keyof DeviceEnrollmentFormData, string>>;
    devices: DeviceOption[];
    employees: EmployeeOption[];
}

export function DeviceEnrollmentForm({ data, setData, errors, devices, employees }: DeviceEnrollmentFormProps) {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <Label htmlFor="biometric_device_id">Device</Label>
                <Select
                    value={data.biometric_device_id}
                    onValueChange={(value) => setData('biometric_device_id', value)}
                >
                    <SelectTrigger id="biometric_device_id" className="mt-1">
                        <SelectValue placeholder="Select a device" />
                    </SelectTrigger>
                    <SelectContent>
                        {devices.map((device) => (
                            <SelectItem key={device.id} value={String(device.id)}>
                                {device.name} ({device.serial})
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.biometric_device_id} className="mt-2" />
            </div>

            <div>
                <Label htmlFor="employee_id">Employee</Label>
                <Select value={data.employee_id} onValueChange={(value) => setData('employee_id', value)}>
                    <SelectTrigger id="employee_id" className="mt-1">
                        <SelectValue placeholder="Select an employee" />
                    </SelectTrigger>
                    <SelectContent>
                        {employees.map((employee) => (
                            <SelectItem key={employee.id} value={String(employee.id)}>
                                {employee.fullName}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.employee_id} className="mt-2" />
            </div>

            <div>
                <Label htmlFor="device_user_id">Device User ID</Label>
                <Input
                    id="device_user_id"
                    name="device_user_id"
                    value={data.device_user_id}
                    className="mt-1"
                    onChange={(e) => setData('device_user_id', e.target.value)}
                />
                <InputError message={errors.device_user_id} className="mt-2" />
            </div>
        </div>
    );
}
