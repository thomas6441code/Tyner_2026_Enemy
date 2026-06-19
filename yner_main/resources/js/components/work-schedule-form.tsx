import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface WorkScheduleFormData {
    name: string;
    start_time: string;
    end_time: string;
    grace_period_minutes: string;
}

interface WorkScheduleFormProps {
    data: WorkScheduleFormData;
    setData: (key: keyof WorkScheduleFormData, value: string) => void;
    errors: Partial<Record<keyof WorkScheduleFormData, string>>;
}

export function WorkScheduleForm({ data, setData, errors }: WorkScheduleFormProps) {
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

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="start_time">Start Time</Label>
                    <Input
                        id="start_time"
                        name="start_time"
                        type="time"
                        value={data.start_time}
                        className="mt-1"
                        onChange={(e) => setData('start_time', e.target.value)}
                    />
                    <InputError message={errors.start_time} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="end_time">End Time</Label>
                    <Input
                        id="end_time"
                        name="end_time"
                        type="time"
                        value={data.end_time}
                        className="mt-1"
                        onChange={(e) => setData('end_time', e.target.value)}
                    />
                    <InputError message={errors.end_time} className="mt-2" />
                </div>
            </div>

            <div>
                <Label htmlFor="grace_period_minutes">Grace Period (minutes)</Label>
                <Input
                    id="grace_period_minutes"
                    name="grace_period_minutes"
                    type="number"
                    min={0}
                    max={120}
                    value={data.grace_period_minutes}
                    className="mt-1"
                    onChange={(e) => setData('grace_period_minutes', e.target.value)}
                />
                <InputError message={errors.grace_period_minutes} className="mt-2" />
            </div>
        </div>
    );
}
