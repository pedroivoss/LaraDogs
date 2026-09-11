import { Head, Link } from '@inertiajs/react';
import { Plus, UserPlus } from 'lucide-react';
import { EmptyState } from '@/components/audit/empty-state';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create, edit, index } from '@/routes/settings/users';

type UserListItem = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    created_at: string;
};

export default function UsersIndex({ users }: { users: UserListItem[] }) {
    return (
        <>
            <Head title="Users" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Users"
                        description="Manage who can sign in to this LaraDogs installation"
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>
                            <Plus className="size-4" />
                            New user
                        </Link>
                    </Button>
                </div>

                {users.length === 0 ? (
                    <EmptyState
                        icon={UserPlus}
                        title="No users yet"
                        description="Create a user to give someone else access to this installation."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell>
                                            <Link
                                                href={edit(user.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {user.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {user.email}
                                        </TableCell>
                                        <TableCell>
                                            {user.is_admin ? (
                                                <Badge>Administrator</Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    User
                                                </Badge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};
