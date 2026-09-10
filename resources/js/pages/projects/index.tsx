import { Head, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { EmptyState } from '@/components/audit/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';

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
    return (
        <>
            <Head title="Projects" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Projects</h1>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderOpen}
                        title="No projects registered yet"
                        description="Register a Laravel project with LaraDogs to start tracking its audit history. This is currently a CLI-only workflow."
                        action={
                            <code className="bg-muted rounded-md px-3 py-1.5 text-xs">
                                php artisan laradogs:project:add
                                /path/to/your/laravel/project
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
