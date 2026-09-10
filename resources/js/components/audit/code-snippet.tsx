/**
 * Renders a finding's code evidence. Safety notes:
 *
 * - Content is interpolated as plain JSX text (`{content}`), which React
 *   escapes automatically — this NEVER uses `dangerouslySetInnerHTML`, so
 *   finding content can never be interpreted as HTML/markup, regardless
 *   of what a scanner observed in the target project's source.
 * - Secret redaction already happened server-side
 *   (`App\Audit\Findings\Redaction\EvidenceRedactor`) before this content
 *   was ever persisted — this component does no redaction of its own and
 *   assumes none is needed here.
 * - A missing snippet (analyzer didn't capture one, or nothing survived
 *   redaction) renders a plain placeholder instead of an empty box.
 */
export function CodeSnippet({
    content,
    lineStart,
}: {
    content: string | null;
    lineStart?: number | null;
}) {
    if (content === null || content.trim() === '') {
        return (
            <p className="text-muted-foreground border-border rounded-md border border-dashed px-3 py-4 text-sm italic">
                No code snippet was captured for this finding.
            </p>
        );
    }

    const lines = content.split('\n');

    return (
        <pre className="bg-muted/50 border-border overflow-x-auto rounded-md border p-3 text-xs leading-relaxed">
            <code className="font-mono">
                {lines.map((line, index) => (
                    <div key={index} className="flex gap-4">
                        {lineStart !== null && lineStart !== undefined && (
                            <span className="text-muted-foreground w-10 shrink-0 text-right select-none">
                                {lineStart + index}
                            </span>
                        )}
                        <span className="whitespace-pre">{line}</span>
                    </div>
                ))}
            </code>
        </pre>
    );
}
