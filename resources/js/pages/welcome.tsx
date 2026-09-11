import { Head, Link, usePage } from '@inertiajs/react';
import {
    FileSearch,
    History,
    LayoutGrid,
    PackageSearch,
    ScanSearch,
    ShieldAlert,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard, login } from '@/routes';

const CAPABILITIES = [
    {
        icon: PackageSearch,
        title: 'Composer dependency auditing',
        description:
            'Known-vulnerability advisories for every dependency in composer.lock.',
    },
    {
        icon: PackageSearch,
        title: 'npm dependency auditing',
        description:
            'The same advisory-based auditing for the frontend dependency tree.',
    },
    {
        icon: ScanSearch,
        title: 'Semgrep static analysis',
        description:
            'Pattern-based static analysis across the codebase, with explicit coverage reporting.',
    },
    {
        icon: ShieldAlert,
        title: 'Laravel-aware security rules',
        description:
            'Rules written for how Laravel applications are actually structured, not generic PHP.',
    },
    {
        icon: FileSearch,
        title: 'Persisted findings & lifecycle',
        description:
            'Findings persist across scans with a full status history — open, confirmed, resolved, and more.',
    },
    {
        icon: History,
        title: 'Scan history',
        description:
            'Every scan is recorded, so results over time stay comparable and auditable.',
    },
] as const;

export default function Welcome({
    has_administrator: hasAdministrator,
}: {
    has_administrator: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="LaraDogs" />
            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="border-b">
                    <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-4">
                        <div className="flex items-center gap-2">
                            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                            </div>
                            <span className="text-lg font-semibold">
                                LaraDogs
                            </span>
                        </div>

                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={dashboard()}>Open Dashboard</Link>
                            </Button>
                        ) : (
                            <Button asChild size="sm" variant="outline">
                                <Link href={login()}>Log in</Link>
                            </Button>
                        )}
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-16 px-6 py-16">
                    <div className="flex flex-col gap-4">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            Laravel application auditing and intelligence.
                        </h1>
                        <p className="text-muted-foreground max-w-2xl text-base">
                            Audit your Laravel application without executing
                            target application code. Self-hosted, persisted
                            findings, and a Dashboard to browse the results.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {CAPABILITIES.map((capability) => (
                            <Card key={capability.title}>
                                <CardContent className="flex flex-col gap-2 pt-6">
                                    <capability.icon
                                        className="text-muted-foreground size-5"
                                        aria-hidden
                                    />
                                    <h2 className="text-sm font-medium">
                                        {capability.title}
                                    </h2>
                                    <p className="text-muted-foreground text-sm">
                                        {capability.description}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    {!auth.user && !hasAdministrator && (
                        <Card className="max-w-2xl">
                            <CardContent className="flex items-start gap-3 pt-6">
                                <LayoutGrid
                                    className="text-muted-foreground mt-0.5 size-5 shrink-0"
                                    aria-hidden
                                />
                                <p className="text-muted-foreground text-sm">
                                    An administrator account has not been
                                    configured yet. See{' '}
                                    <code className="bg-muted rounded px-1 py-0.5 text-xs">
                                        docs/self-hosting.md
                                    </code>{' '}
                                    for how to provision the first
                                    administrator.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </main>

                <footer className="text-muted-foreground border-t px-6 py-6 text-center text-xs">
                    LaraDogs — self-hosted Laravel application auditing.
                </footer>
            </div>
        </>
    );
}
