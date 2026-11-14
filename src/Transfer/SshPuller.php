<?php

declare(strict_types=1);

namespace Phattarachai\DbSnapshotSyncLaravel\Transfer;

use Closure;

/**
 * Runs an export command on a box over ssh and writes its stdout straight to a
 * local file, with no copy in PHP memory or a temp file. ssh's stderr is read
 * line by line: the export's closing size + sha256 line is checked against the
 * file, everything else is passed to $onProgress.
 *
 * Uses the OpenSSH client already on macOS, Linux, WSL2 and Windows 10+, with
 * the user's own ~/.ssh/config, keys and agent.
 */
class SshPuller
{
    private const int TAIL_LINES = 15;

    public function __construct(private readonly string $binary = 'ssh') {}

    /**
     * @param  list<string>  $artisan  the export command and its arguments, e.g. ['snapshot:export', '--fresh']
     * @param  (Closure(string): void)|null  $onProgress
     *
     * @throws TransferFailed when ssh fails, the export fails, or the file does not match what was sent
     */
    public function pull(SshSource $source, array $artisan, string $localPath, ?Closure $onProgress = null): ExportResult
    {
        $command = $source->command($this->binary, $artisan);

        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $localPath, 'wb'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            @unlink($localPath);

            throw new TransferFailed("Could not start [{$this->binary}]. Is the OpenSSH client installed and on PATH?");
        }

        fclose($pipes[0]);

        $result = null;
        $tail = [];

        while (($line = fgets($pipes[2])) !== false) {
            $line = rtrim($line, "\r\n");
            $parsed = ExportResult::fromLine($line);

            if ($parsed instanceof ExportResult) {
                $result = $parsed;

                continue;
            }

            if ($line === '') {
                continue;
            }

            $tail = array_slice([...$tail, $line], -self::TAIL_LINES);

            if ($onProgress instanceof Closure) {
                $onProgress($line);
            }
        }

        fclose($pipes[2]);
        $exitCode = proc_close($process);

        try {
            return $this->verified($source, $artisan, $localPath, $exitCode, $result, $tail);
        } catch (TransferFailed $e) {
            @unlink($localPath);

            throw $e;
        }
    }

    /**
     * @param  list<string>  $artisan
     * @param  list<string>  $tail
     */
    private function verified(SshSource $source, array $artisan, string $localPath, int $exitCode, ?ExportResult $result, array $tail): ExportResult
    {
        if ($exitCode !== 0 || ! $result instanceof ExportResult) {
            $stderr = trim(implode("\n", $tail)."\n".$this->textHead($localPath));

            throw new TransferFailed($this->diagnose($source, $artisan[0] ?? 'snapshot:export', $exitCode, $stderr));
        }

        clearstatcache(true, $localPath);
        $size = is_file($localPath) ? (int) filesize($localPath) : -1;

        if ($size !== $result->size || hash_file('sha256', $localPath) !== $result->sha256) {
            throw new TransferFailed("The download of {$result->name} is incomplete or corrupt: got {$size} of {$result->size} bytes, or a different sha256. Nothing was sanitized or loaded; run it again.");
        }

        return $result;
    }

    /**
     * Artisan prints its own errors (an unknown command, a boot failure) to
     * stdout, so on a failure the "download" is often that message.
     */
    private function textHead(string $localPath): string
    {
        $head = is_file($localPath) ? (string) file_get_contents($localPath, length: 2000) : '';

        return $head !== '' && ! str_contains($head, "\0") && mb_check_encoding($head, 'UTF-8') ? trim($head) : '';
    }

    private function diagnose(SshSource $source, string $export, int $exitCode, string $stderr): string
    {
        $detail = $stderr === '' ? '' : "\n{$stderr}";

        return match (true) {
            str_contains($stderr, 'sudo: a password is required'),
            str_contains($stderr, 'sudo: a terminal is required') => "Your ssh user on [{$source->host}] cannot run artisan as [{$source->runAs}] without a password. Grant passwordless sudo to that user (see the README's sudoers rule), or drop run_as when you log in as the app's user.{$detail}",
            str_contains($stderr, "Command \"{$export}\" is not defined") => "[{$source->host}] runs a version of phattarachai/db-snapshot-sync-laravel without {$export}. Deploy ^1.6 there first.{$detail}",
            str_contains($stderr, 'No such file or directory') && str_contains($stderr, 'cd:') => "[{$source->path}] does not exist on [{$source->host}]. Set the source's path to the app's directory there.{$detail}",
            $exitCode === 255 => "ssh could not log in to [{$source->host}]. Check that `ssh {$source->host}` works without a prompt (keys and agent; BatchMode is on).{$detail}",
            default => "{$export} failed on [{$source->host}] (exit {$exitCode}). Nothing was sanitized or loaded.{$detail}",
        };
    }
}
