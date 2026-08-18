import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface WorkLocationFormData {
    name: string;
    address: string;
    latitude: string;
    longitude: string;
    radius_meters: string;
    is_active: string;
}

interface WorkLocationFormProps {
    data: WorkLocationFormData;
    setData: (key: keyof WorkLocationFormData, value: string) => void;
    errors: Partial<Record<keyof WorkLocationFormData, string>>;
}

export function WorkLocationForm({ data, setData, errors }: WorkLocationFormProps) {
    /**
     * Convenience only. The coordinates typed here define the geofence centre; they are never
     * the coordinates a check-in is judged against, which always come from the phone at the
     * moment of the punch and are measured against this centre on the server.
     */
    const useCurrentPosition = () => {
        if (!('geolocation' in navigator)) {
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setData('latitude', position.coords.latitude.toFixed(7));
                setData('longitude', position.coords.longitude.toFixed(7));
            },
            () => {
                // Silent: the fields stay editable by hand, which is the normal path when an
                // admin is configuring a site they are not standing in.
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

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
                <Label htmlFor="address">Address</Label>
                <Input
                    id="address"
                    name="address"
                    value={data.address}
                    className="mt-1"
                    onChange={(e) => setData('address', e.target.value)}
                />
                <InputError message={errors.address} className="mt-2" />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="latitude">Latitude</Label>
                    <Input
                        id="latitude"
                        name="latitude"
                        type="number"
                        step="0.0000001"
                        min={-90}
                        max={90}
                        value={data.latitude}
                        className="mt-1"
                        onChange={(e) => setData('latitude', e.target.value)}
                    />
                    <InputError message={errors.latitude} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        name="longitude"
                        type="number"
                        step="0.0000001"
                        min={-180}
                        max={180}
                        value={data.longitude}
                        className="mt-1"
                        onChange={(e) => setData('longitude', e.target.value)}
                    />
                    <InputError message={errors.longitude} className="mt-2" />
                </div>
            </div>

            <button
                type="button"
                onClick={useCurrentPosition}
                className="self-start text-xs font-medium text-primary underline-offset-4 hover:underline"
            >
                Use my current position
            </button>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <Label htmlFor="radius_meters">Radius (metres)</Label>
                    <Input
                        id="radius_meters"
                        name="radius_meters"
                        type="number"
                        min={20}
                        max={5000}
                        value={data.radius_meters}
                        className="mt-1"
                        onChange={(e) => setData('radius_meters', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Minimum 20m — GPS is rarely more accurate than that outdoors.
                    </p>
                    <InputError message={errors.radius_meters} className="mt-2" />
                </div>

                <div>
                    <Label htmlFor="is_active">Status</Label>
                    <Select value={data.is_active} onValueChange={(value) => setData('is_active', value)}>
                        <SelectTrigger id="is_active" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1">Active</SelectItem>
                            <SelectItem value="0">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.is_active} className="mt-2" />
                </div>
            </div>
        </div>
    );
}
