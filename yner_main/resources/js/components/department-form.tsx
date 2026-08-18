import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

interface Option {
    id: number;
    name: string;
}

interface DepartmentFormData {
    name: string;
    description: string;
    work_location_id: string;
}

interface DepartmentFormProps {
    data: DepartmentFormData;
    setData: (key: keyof DepartmentFormData, value: string) => void;
    errors: Partial<Record<keyof DepartmentFormData, string>>;
    workLocations: Option[];
}

const NONE = '__none__';

export function DepartmentForm({ data, setData, errors, workLocations }: DepartmentFormProps) {
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

            <div>
                <Label htmlFor="work_location_id">Default Work Location</Label>
                <Select
                    value={data.work_location_id || NONE}
                    onValueChange={(value) => setData('work_location_id', value === NONE ? '' : value)}
                >
                    <SelectTrigger id="work_location_id" className="mt-1">
                        <SelectValue placeholder="— None —" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>— None —</SelectItem>
                        {workLocations.map((workLocation) => (
                            <SelectItem key={workLocation.id} value={String(workLocation.id)}>
                                {workLocation.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="mt-1 text-xs text-muted-foreground">
                    Inherited by every employee in this department who has no location of their own.
                </p>
                <InputError message={errors.work_location_id} className="mt-2" />
            </div>
        </div>
    );
}
