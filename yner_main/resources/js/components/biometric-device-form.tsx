import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface BiometricDeviceFormData {
    name: string;
    type: string;
    serial: string;
    host: string;
    port: string;
    username: string;
    password: string;
    status: string;
}

interface BiometricDeviceFormProps {
    data: BiometricDeviceFormData;
    setData: (key: keyof BiometricDeviceFormData, value: string) => void;
    errors: Partial<Record<keyof BiometricDeviceFormData, string>>;
    isEdit?: boolean;
}

export function BiometricDeviceForm({ data, setData, errors, isEdit = false }: BiometricDeviceFormProps) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1"
                        autoFocus
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="type">Type</Label>
                    <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                        <SelectTrigger id="type" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="stub">Stub</SelectItem>
                            <SelectItem value="zkteco">ZKTeco</SelectItem>
                            <SelectItem value="hikvision">Hikvision</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} className="mt-2" />
                </div>
            </div>

            <div>
                <Label htmlFor="serial">Serial</Label>
                <Input
                    id="serial"
                    name="serial"
                    value={data.serial}
                    className="mt-1"
                    onChange={(e) => setData('serial', e.target.value)}
                />
                <InputError message={errors.serial} className="mt-2" />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="host">Host</Label>
                    <Input
                        id="host"
                        name="host"
                        value={data.host}
                        className="mt-1"
                        onChange={(e) => setData('host', e.target.value)}
                    />
                    <InputError message={errors.host} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="port">Port</Label>
                    <Input
                        id="port"
                        name="port"
                        type="number"
                        value={data.port}
                        className="mt-1"
                        onChange={(e) => setData('port', e.target.value)}
                    />
                    <InputError message={errors.port} className="mt-2" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="username">Username</Label>
                    <Input
                        id="username"
                        name="username"
                        value={data.username}
                        className="mt-1"
                        onChange={(e) => setData('username', e.target.value)}
                    />
                    <InputError message={errors.username} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        name="password"
                        type="password"
                        value={data.password}
                        className="mt-1"
                        placeholder={isEdit ? 'Leave blank to keep current password' : ''}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>
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
    );
}
