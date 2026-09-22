<?php

namespace Tests\Support\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A throwaway REAL Git repository created with the installed `git` binary
 * during a test (never committed as `.git` test data). Fixture commands run
 * with the host's global/system config neutralized so the fixture is
 * deterministic; the code under test is NOT run through this class.
 */
final class GitFixture
{
    /** @var list<string> */
    private static array $created = [];

    private function __construct(public readonly string $path)
    {
        self::$created[] = $path;
    }

    /**
     * Removes every fixture created since the last call (use in afterEach).
     */
    public static function cleanupAll(): void
    {
        foreach (self::$created as $path) {
            if (is_dir($path)) {
                (new Process(['chmod', '-R', 'u+rwX', $path]))->run();
                (new Process(['rm', '-rf', $path]))->run();
            }
        }

        self::$created = [];
    }

    public static function available(): bool
    {
        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * An empty directory (not a repository).
     */
    public static function directory(): self
    {
        $path = sys_get_temp_dir().'/laradogs-git-'.bin2hex(random_bytes(6));
        mkdir($path, 0o777, true);

        return new self((string) realpath($path));
    }

    /**
     * A normal repository on branch `main` with one commit.
     */
    public static function repository(string $subject = 'Initial commit'): self
    {
        $fixture = self::directory();
        $fixture->git('init', '-q', '-b', 'main');
        $fixture->write('README.md', "# fixture\n");
        $fixture->commitAll($subject);

        return $fixture;
    }

    /**
     * Initialized but with no commit (unborn HEAD).
     */
    public static function unborn(): self
    {
        $fixture = self::directory();
        $fixture->git('init', '-q', '-b', 'main');

        return $fixture;
    }

    public static function bare(): self
    {
        $fixture = self::directory();
        $fixture->git('init', '-q', '--bare', '-b', 'main');

        return $fixture;
    }

    public function write(string $relative, string $contents): void
    {
        $file = $this->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, $contents);
    }

    public function commitAll(string $subject): string
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $subject);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    public function sha(): string
    {
        return trim($this->git('rev-parse', 'HEAD'));
    }

    public function detach(): void
    {
        $this->git('checkout', '-q', '--detach');
    }

    public function config(string $key, string $value): void
    {
        $this->git('config', '--local', $key, $value);
    }

    public function git(string ...$args): string
    {
        $process = new Process(['git', ...$args], $this->path, [
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Fixture Author',
            'GIT_AUTHOR_EMAIL' => 'author-secret@example.invalid',
            'GIT_COMMITTER_NAME' => 'Fixture Committer',
            'GIT_COMMITTER_EMAIL' => 'committer-secret@example.invalid',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
