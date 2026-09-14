import { Form, Head, router } from '@inertiajs/react';
import UsersController from '@/actions/App/Http/Controllers/Settings/UsersController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    activate,
    deactivate,
    demote,
    edit,
    index,
    promote,
} from '@/routes/settings/users';
import type { Role } from '@/types';

type TargetUser = {
    id: number;
    name: string;
    email: string;
    role: Role;
    is_active: boolean;
};

export default function EditUser({
    target_user: targetUser,
    can_change_role: canChangeRole,
}: {
    target_user: TargetUser;
    can_change_role: boolean;
}) {
    function toggleActive() {
        const action = targetUser.is_active ? deactivate : activate;
        const verb = targetUser.is_active ? 'deactivate' : 'activate';

        router.put(
            action(targetUser.id),
            {},
            {
                onBefore: () =>
                    confirm(
                        `${verb.charAt(0).toUpperCase() + verb.slice(1)} ${targetUser.name}?`,
                    ),
            },
        );
    }

    function changeRole() {
        const action = targetUser.role === 'admin' ? demote : promote;
        const verb = targetUser.role === 'admin' ? 'demote' : 'promote';

        router.put(
            action(targetUser.id),
            {},
            {
                onBefore: () =>
                    confirm(
                        `${verb.charAt(0).toUpperCase() + verb.slice(1)} ${targetUser.name}${verb === 'promote' ? ' to Admin' : ' to User'}?`,
                    ),
            },
        );
    }

    return (
        <>
            <Head title={`Edit ${targetUser.name}`} />

            <div className="space-y-10">
                <div className="space-y-6">
                    <div className="flex flex-wrap items-center gap-3">
                        <Heading
                            variant="small"
                            title={targetUser.name}
                            description={targetUser.email}
                        />
                        {targetUser.role === 'admin' && <Badge>Admin</Badge>}
                        {targetUser.is_active ? (
                            <Badge variant="outline">Active</Badge>
                        ) : (
                            <Badge variant="destructive">Inactive</Badge>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={toggleActive}>
                            {targetUser.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                        {canChangeRole && (
                            <Button variant="outline" onClick={changeRole}>
                                {targetUser.role === 'admin'
                                    ? 'Demote to User'
                                    : 'Promote to Admin'}
                            </Button>
                        )}
                    </div>

                    <Form
                        {...UsersController.update.form(targetUser.id)}
                        className="max-w-sm space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={targetUser.name}
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        defaultValue={targetUser.email}
                                        autoComplete="username"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Save
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="space-y-6 border-t pt-6">
                    <Heading
                        variant="small"
                        title="Set password"
                        description="Set a new password for this user. They are not notified automatically."
                    />

                    <Form
                        {...UsersController.updatePassword.form(targetUser.id)}
                        resetOnSuccess
                        className="max-w-sm space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        New password
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm new password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <Button disabled={processing} variant="outline">
                                    {processing && <Spinner />}
                                    Set password
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

EditUser.layout = (props: { target_user: TargetUser }) => ({
    breadcrumbs: [
        { title: 'Users', href: index() },
        { title: props.target_user.name, href: edit(props.target_user.id) },
    ],
});
