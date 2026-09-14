import { Head, Link, usePage } from '@inertiajs/react';
import { FolderOpen, Plus } from 'lucide-react';
import { EmptyState } from '@/components/audit/empty-state';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import {
    add as projectAdd,
    index as projectsIndex,
    show as projectShow,
} from '@/routes/projects';

type ProjectListItem = {
    id: string;
    name: string;
    path: string;
    open_findings_count: number;
    last_scan: { id: string; status: string; started_at: string } | null;
};

export default function ProjectsIndex({
    projects,
}: {
    projects: ProjectListItem[];
}) {
    const { auth } = usePage().props;
    const canRegisterProjects =
        auth.user?.role === 'owner' || auth.user?.role === 'admin';

    return (
        <>
            <Head title="Projects" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Projects</h1>
                    {canRegisterProjects && (
                        <Button asChild size="sm">
                            <Link href={projectAdd()}>
                                <Plus className="size-4" />
                                Add Project
                            </Link>
                        </Button>
                    )}
                </div>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderOpen}
                        title="No projects registered yet"
                        description={
                            canRegisterProjects
                                ? 'Add a project from a directory mounted under /projects, or register one from the command line.'
                                : 'No projects have been registered yet. An Owner or Admin can add one.'
                        }
                        action={
                            <code className="bg-muted rounded-md px-3 py-1.5 text-xs">
                                docker compose exec app php artisan
                                laradogs:project:add /projects/your-project
                            </code>
                        }
                    />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Path</TableHead>
                                    <TableHead>Last scan</TableHead>
                                    <TableHead>Open findings</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {projects.map((project) => (
                                    <TableRow key={project.id}>
                                        <TableCell>
                                            <Link
                                                href={projectShow(project.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {project.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell
                                            className="text-muted-foreground max-w-xs truncate font-mono text-xs"
                                            title={project.path}
                                        >
                                            {project.path}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {project.last_scan ? (
                                                <>
                                                    {new Date(
                                                        project.last_scan
                                                            .started_at,
                                                    ).toLocaleString()}
                                                    <span className="ml-1">
                                                        (
                                                        {
                                                            project.last_scan
                                                                .status
                                                        }
                                                        )
                                                    </span>
                                                </>
                                            ) : (
                                                'Never scanned'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {project.open_findings_count}
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

ProjectsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
    ],
};
