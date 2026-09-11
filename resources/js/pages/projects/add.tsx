import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, FolderOpen, FolderSearch } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/audit/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    add as projectAdd,
    index as projectsIndex,
    store as projectStore,
} from '@/routes/projects';

type DirectoryCandidate = {
    name: string;
    already_registered: boolean;
    looks_like_laravel: boolean;
};

export default function AddProject({
    root_available: rootAvailable,
    directories,
}: {
    root_available: boolean;
    directories: DirectoryCandidate[];
}) {
    const { errors } = usePage().props;
    const [selected, setSelected] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function register() {
        if (selected === null) {
            return;
        }

        setSubmitting(true);
        router.post(
            projectStore(),
            { directory: selected, name: name || null },
            { onFinish: () => setSubmitting(false) },
        );
    }

    return (
        <>
            <Head title="Add Project" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Add Project</h1>

                {!rootAvailable ? (
                    <EmptyState
                        icon={FolderSearch}
                        title="No project directory is available to LaraDogs"
                        description="Configure LARADOGS_PROJECTS_PATH in .env to point at a directory of Laravel projects, then recreate the Docker container (docker compose down && docker compose up -d). See docs/self-hosting.md."
                    />
                ) : directories.length === 0 ? (
                    <EmptyState
                        icon={FolderSearch}
                        title="No project directory is available to LaraDogs"
                        description="The configured project root is mounted but currently empty. Add a Laravel project directory to it, then reload this page."
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        {errors.directory && (
                            <p className="text-destructive text-sm">
                                {errors.directory}
                            </p>
                        )}

                        <div className="divide-y rounded-lg border">
                            {directories.map((directory) => (
                                <button
                                    key={directory.name}
                                    type="button"
                                    disabled={directory.already_registered}
                                    onClick={() => setSelected(directory.name)}
                                    className={`hover:bg-muted/50 flex w-full items-center justify-between gap-2 px-4 py-3 text-left disabled:cursor-not-allowed disabled:opacity-60 ${
                                        selected === directory.name
                                            ? 'bg-muted'
                                            : ''
                                    }`}
                                >
                                    <div className="flex items-center gap-2">
                                        <FolderOpen
                                            className="text-muted-foreground size-4"
                                            aria-hidden
                                        />
                                        <span className="font-mono text-sm">
                                            {directory.name}
                                        </span>
                                        {directory.looks_like_laravel && (
                                            <Badge variant="outline">
                                                Laravel detected
                                            </Badge>
                                        )}
                                    </div>
                                    {directory.already_registered ? (
                                        <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                            <CheckCircle2 className="size-3.5" />
                                            Already registered
                                        </span>
                                    ) : (
                                        selected === directory.name && (
                                            <CheckCircle2 className="text-primary size-4" />
                                        )
                                    )}
                                </button>
                            ))}
                        </div>

                        {selected !== null && (
                            <div className="flex flex-col gap-3 rounded-lg border p-4 sm:max-w-sm">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="name">
                                        Display name (optional)
                                    </Label>
                                    <Input
                                        id="name"
                                        value={name}
                                        onChange={(event) =>
                                            setName(event.target.value)
                                        }
                                        placeholder={selected}
                                    />
                                </div>
                                <Button
                                    onClick={register}
                                    disabled={submitting}
                                >
                                    Register {selected}
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

AddProject.layout = () => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        { title: 'Add Project', href: projectAdd() },
    ],
});
