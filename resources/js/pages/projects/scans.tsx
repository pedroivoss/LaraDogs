import { Head, Link, router } from '@inertiajs/react';
import { DataPagination } from '@/components/audit/data-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { show as scanShow } from '@/routes/projects/scans';
import {
    index as projectsIndex,
    scans as projectScansRoute,
    show as projectShow,
} from '@/routes/projects';
import type { Pagination, ScanStatus } from '@/types/audit';

type ScanListItem = {
    id: string;
    status: ScanStatus;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
    findings_summary: {
        observed: number;
        auto_resolved: number;
        by_severity: Record<string, number>;
    } | null;
};

const STATUS_STYLE: Record<ScanStatus, string> = {
    running: 'text-blue-600 dark:text-blue-400',
    completed: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
};

export default function ProjectScans({
    project,
    scans,
    pagination,
}: {
    project: { id: string; name: string };
    scans: ScanListItem[];
    pagination: Pagination;
}) {
    function goToPage(page: number) {
        router.get(
            projectScansRoute(project.id),
            { page },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title={`Scan history — ${project.name}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Scan history</h1>

                {scans.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No scans have been recorded yet.
                    </p>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Started</TableHead>
                                        <TableHead>Finished</TableHead>
                                        <TableHead>Duration</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Findings observed</TableHead>
                                        <TableHead>Auto-resolved</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {scans.map((scan) => (
                                        <TableRow key={scan.id}>
                                            <TableCell>
                                                <Link
                                                    href={scanShow({
                                                        project: project.id,
                                                        scan: scan.id,
                                                    })}
                                                    className="font-medium hover:underline"
                                                >
                                                    {new Date(
                                                        scan.started_at,
                                                    ).toLocaleString()}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {scan.finished_at
                                                    ? new Date(
                                                          scan.finished_at,
                                                      ).toLocaleString()
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {scan.duration_ms !== null
                                                    ? `${scan.duration_ms}ms`
                                                    : '—'}
                                            </TableCell>
                                            <TableCell
                                                className={`font-medium ${STATUS_STYLE[scan.status]}`}
                                            >
                                                {scan.status}
                                            </TableCell>
                                            <TableCell>
                                                {scan.findings_summary
                                                    ?.observed ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {scan.findings_summary
                                                    ?.auto_resolved ?? '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <DataPagination
                            pagination={pagination}
                            onPageChange={goToPage}
                        />
                    </>
                )}
            </div>
        </>
    );
}

ProjectScans.layout = (props: { project: { id: string; name: string } }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Projects', href: projectsIndex() },
        { title: props.project.name, href: projectShow(props.project.id) },
        { title: 'Scan history', href: projectScansRoute(props.project.id) },
    ],
});
