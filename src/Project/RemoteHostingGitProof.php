<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Verifies *only* the Git ancestry portion of a locally approved hosting plan.
 *
 * The caller must first fetch the fixed Cowprod repository into a trusted,
 * root-owned checkout. This method deliberately performs no network request,
 * release, chmod, installation, remote job or systemd operation.
 *
 * A successful proof is NOT an authorization to run application code.
 */
final class RemoteHostingGitProof
{
    /**
     * @param array<string,mixed> $plan Output of RemoteHostingReleasePlan::preview()
     * @return array{verified:bool,repository:string,minimum_revision:string,
     *   revision:string,main_revision:string,ready_to_execute:bool,scope:string}
     */
    public static function verify(array $plan, string $checkout): array
    {
        $repository = $plan['repository'] ?? null;
        $minimum = $plan['minimum_revision'] ?? null;
        $revision = $plan['revision'] ?? null;
        if (!is_string($repository)
            || preg_match('~^Cowprod/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D', $repository) !== 1
            || !self::sha($minimum) || !self::sha($revision)
            || ($plan['ready_to_execute'] ?? null) !== false
            || !in_array('git_main_ancestry', $plan['verification_required'] ?? [], true)) {
            throw new InvalidArgumentException('Untrusted hosting Git plan');
        }
        if ($checkout === '' || $checkout[0] !== '/'
            || !is_dir($checkout) || is_link($checkout)
            || realpath($checkout) !== $checkout
            || !is_dir($checkout . '/.git') || is_link($checkout . '/.git')) {
            throw new InvalidArgumentException('Untrusted Git checkout');
        }

        $origin = self::git($checkout, ['config', '--get', 'remote.origin.url']);
        if ($origin !== 'https://github.com/' . $repository . '.git') {
            throw new RuntimeException('Unexpected repository origin');
        }
        if (self::git($checkout, ['rev-parse', '--show-toplevel']) !== $checkout
            || self::git($checkout, ['rev-parse', '--is-shallow-repository']) !== 'false'
            || self::git($checkout, ['status', '--porcelain', '--untracked-files=no']) !== '') {
            throw new RuntimeException('Repository is shallow, modified or not canonical');
        }

        $main = self::git($checkout, ['rev-parse', '--verify', 'refs/remotes/origin/main^{commit}']);
        if (!self::sha($main)) {
            throw new RuntimeException('Invalid origin/main revision');
        }
        foreach ([$minimum, $revision] as $sha) {
            if (self::git($checkout, ['cat-file', '-t', $sha]) !== 'commit') {
                throw new RuntimeException('Requested object is not a commit');
            }
        }
        // These two directed tests reject commits from unrelated branches,
        // old history before the policy's pin and objects absent from main.
        self::git($checkout, ['merge-base', '--is-ancestor', $minimum, $revision]);
        self::git($checkout, ['merge-base', '--is-ancestor', $revision, $main]);

        return [
            'verified' => true,
            'repository' => $repository,
            'minimum_revision' => $minimum,
            'revision' => $revision,
            'main_revision' => $main,
            'ready_to_execute' => false,
            'scope' => 'git_main_ancestry_only',
        ];
    }

    private static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
    }

    /** @param list<string> $args */
    private static function git(string $checkout, array $args): string
    {
        // Never use a shell or an argv supplied by the remote job. Remove
        // inherited Git configuration override variables, including GIT_DIR,
        // GIT_CONFIG_COUNT and GIT_OBJECT_DIRECTORY, before running Git.
        $env = [];
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'GIT_')) {
                $env[$name] = false;
            }
        }
        $env += [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0',
            'LC_ALL' => 'C',
        ];
        $process = new Process(
            array_merge(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $checkout], $args),
            null,
            $env
        );
        $process->setTimeout(10);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Git provenance or ancestry verification failed');
        }
        return trim($process->getOutput());
    }
}
