import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface DepartmentFormData {
    name: string;
    description: string;
}

interface DepartmentFormProps {
    data: DepartmentFormData;
    setData: (key: keyof DepartmentFormData, value: string) => void;
    errors: Partial<Record<keyof DepartmentFormData, string>>;
}

export function DepartmentForm({ data, setData, errors }: DepartmentFormProps) {
    return (
        <div className="flex flex-col gap-4">
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
                <Label htmlFor="description">Description</Label>
                <Textarea
                    id="description"
                    name="description"
                    rows={3}
                    value={data.description}
                    className="mt-1"
                    onChange={(e) => setData('description', e.target.value)}
                />
                <InputError message={errors.description} className="mt-2" />
            </div>
        </div>
    );
}
