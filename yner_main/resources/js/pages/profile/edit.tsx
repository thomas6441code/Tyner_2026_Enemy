import { Head } from '@inertiajs/react';

import { Card, CardContent } from '@/components/ui/card';
import { DeleteUserForm } from '@/components/delete-user-form';
import { UpdatePasswordForm } from '@/components/update-password-form';
import { UpdateProfileInformationForm } from '@/components/update-profile-information-form';
import AppLayout from '@/layouts/app-layout';

export default function EditProfile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    return (
        <AppLayout header={<h2 className="text-lg font-semibold">Profile</h2>}>
            <Head title="Profile" />

            <div className="grid md:grid-cols-2 grid-cols-1 gap-2">
                <Card>
                    <CardContent className="p-6">
                        <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-6">
                        <UpdatePasswordForm />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-6">
                        <DeleteUserForm />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
