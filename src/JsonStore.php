<?php
declare(strict_types=1);

namespace Researched;

final class JsonStore
{
    private string $path;
    private string $lockPath;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Support::rootPath(Support::env('DATA_FILE', 'data/state.json') ?? 'data/state.json');
        $this->lockPath = $this->path . '.lock';
        $this->initialise();
    }

    /**
     * @return array<string,mixed>
     */
    public function read(): array
    {
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open data lock file.');
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Unable to lock data store for reading.');
            }
            return $this->readUnlocked();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Apply a mutation while holding an exclusive lock.
     *
     * @template T
     * @param callable(array<string,mixed>&):T $mutator
     * @return T
     */
    public function mutate(callable $mutator): mixed
    {
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open data lock file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock data store for writing.');
            }
            $state = $this->readUnlocked();
            $result = $mutator($state);
            $this->writeUnlocked($state);
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function initialise(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create data directory.');
        }

        if (!is_file($this->path)) {
            $this->writeUnlocked($this->emptyState());
        }

        if (!is_file($this->lockPath)) {
            touch($this->lockPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readUnlocked(): array
    {
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return $this->emptyState();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The JSON data store is corrupt or unreadable.');
        }

        return array_replace_recursive($this->emptyState(), $decoded);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeUnlocked(array $state): void
    {
        $state['schema_version'] = 1;
        $state['updated_at'] = Support::now();
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write temporary data file.');
        }

        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to replace data file atomically.');
        }
        @chmod($this->path, 0660);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyState(): array
    {
        return [
            'schema_version' => 1,
            'updated_at' => Support::now(),
            'ideas' => [],
            'versions' => [],
            'claims' => [],
            'sources' => [],
            'challenges' => [],
            'research_runs' => [],
            'activities' => [],
        ];
    }
}
