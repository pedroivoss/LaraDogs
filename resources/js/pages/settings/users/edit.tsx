import { Form, Head } from '@inertiajs/react';
import UsersController from '@/actions/App/Http/Controllers/Settings/UsersController';
import { Badge } from '@/components/ui/badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, index } from '@/routes/settings/users';

type TargetUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
};

export default function EditUser({
    target_user: targetUser,
}: {
    target_user: TargetUser;
}) {
    return (
        <>
            <Head title={`Edit ${targetUser.name}`} />

            <div className="space-y-10">
                <div className="space-y-6">
                    <div className="flex items-center gap-3">
                        <Heading
                            variant="small"
                            title={targetUser.name}
                            description={targetUser.email}
                        />
                        {targetUser.is_admin && <Badge>Administrator</Badge>}
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
