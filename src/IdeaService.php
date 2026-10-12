<?php
declare(strict_types=1);

namespace Researched;

final class IdeaService
{
    public function __construct(private readonly JsonStore $store)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listIdeas(): array
    {
        $state = $this->store->read();
        $ideas = is_array($state['ideas'] ?? null) ? $state['ideas'] : [];
        usort($ideas, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return $ideas;
    }

    /**
     * @return array<string,mixed>
     */
    public function createIdea(string $title, string $originalText): array
    {
        $title = Support::cleanText($title, 160);
        $originalText = Support::preserveText($originalText, 30000);
        if (trim($originalText) === '') {
            throw new \InvalidArgumentException('The original idea cannot be empty.');
        }
        if ($title === '') {
            $title = Support::truncate(preg_replace('/\s+/', ' ', $originalText) ?? $originalText, 80);
        }

        return $this->store->mutate(function (array &$state) use ($title, $originalText): array {
            $ideaId = Support::id('idea');
            $versionId = Support::id('ver');
            $now = Support::now();

            $idea = [
                'id' => $ideaId,
                'title' => $title,
                'original_text' => $originalText,
                'status' => 'captured',
                'current_version_id' => $versionId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $idea['hash'] = Support::stableRecordHash($idea);

            $version = [
                'id' => $versionId,
                'idea_id' => $ideaId,
                'version_number' => 1,
                'kind' => 'original',
                'summary' => $originalText,
                'problem' => '',
                'mechanism' => '',
                'intended_result' => '',
                'assumptions' => [],
                'ambiguities' => [],
                'success_criteria' => [],
                'failure_criteria' => [],
                'accepted' => true,
                'model' => null,
                'created_at' => $now,
            ];
            $version['hash'] = Support::stableRecordHash($version);

            $state['ideas'][] = $idea;
            $state['versions'][] = $version;
            $state['activities'][] = $this->activity($ideaId, 'idea.created', 'Original idea captured and preserved.', [
                'version_id' => $versionId,
                'idea_hash' => $idea['hash'],
            ]);

            return $idea;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function getBundle(string $ideaId): array
    {
        $state = $this->store->read();
        $idea = $this->findById($state['ideas'] ?? [], $ideaId);
        if ($idea === null) {
            throw new \RuntimeException('Idea not found.');
        }

        $filter = static fn (mixed $row): bool => is_array($row) && ($row['idea_id'] ?? null) === $ideaId;
        $versions = array_values(array_filter($state['versions'] ?? [], $filter));
        $claims = array_values(array_filter($state['claims'] ?? [], $filter));
        $sources = array_values(array_filter($state['sources'] ?? [], $filter));
        $challenges = array_values(array_filter($state['challenges'] ?? [], $filter));
        $researchRuns = array_values(array_filter($state['research_runs'] ?? [], $filter));
        $activities = array_values(array_filter($state['activities'] ?? [], $filter));

        usort($versions, static fn (array $a, array $b): int => ((int) ($a['version_number'] ?? 0)) <=> ((int) ($b['version_number'] ?? 0)));
        usort($sources, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        usort($challenges, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        usort($activities, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $currentVersion = null;
        foreach ($versions as $version) {
            if (($version['id'] ?? null) === ($idea['current_version_id'] ?? null)) {
                $currentVersion = $version;
                break;
            }
        }

        $currentClaims = $currentVersion === null
            ? []
            : array_values(array_filter($claims, static fn (array $claim): bool => ($claim['version_id'] ?? null) === ($currentVersion['id'] ?? null)));

        return [
            'idea' => $idea,
            'current_version' => $currentVersion,
            'versions' => $versions,
            'claims' => $claims,
            'current_claims' => $currentClaims,
            'sources' => $sources,
            'challenges' => $challenges,
            'research_runs' => $researchRuns,
            'activities' => $activities,
            'counts' => [
                'versions' => count($versions),
                'claims' => count($claims),
                'sources' => count($sources),
                'trusted_sources' => count(array_filter($sources, static fn (array $source): bool => ($source['trust_status'] ?? '') === 'trusted')),
                'open_challenges' => count(array_filter($challenges, static fn (array $challenge): bool => ($challenge['status'] ?? '') === 'open')),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    public function addInterpretation(string $ideaId, array $analysis, ?string $model): array
    {
        return $this->store->mutate(function (array &$state) use ($ideaId, $analysis, $model): array {
            $ideaIndex = $this->findIndexById($state['ideas'] ?? [], $ideaId);
            if ($ideaIndex === null) {
                throw new \RuntimeException('Idea not found.');
            }

            $versionCount = count(array_filter(
                $state['versions'] ?? [],
                static fn (mixed $row): bool => is_array($row) && ($row['idea_id'] ?? null) === $ideaId
            ));

            $now = Support::now();
            $version = [
                'id' => Support::id('ver'),
                'idea_id' => $ideaId,
                'version_number' => $versionCount + 1,
                'kind' => 'interpretation',
                'summary' => Support::cleanText($analysis['summary'] ?? '', 12000),
                'problem' => Support::cleanText($analysis['problem'] ?? '', 6000),
                'mechanism' => Support::cleanText($analysis['mechanism'] ?? '', 6000),
                'intended_result' => Support::cleanText($analysis['intended_result'] ?? '', 6000),
                'assumptions' => $this->cleanStringList($analysis['assumptions'] ?? []),
                'ambiguities' => $this->cleanStringList($analysis['ambiguities'] ?? []),
                'success_criteria' => $this->cleanStringList($analysis['success_criteria'] ?? []),
                'failure_criteria' => $this->cleanStringList($analysis['failure_criteria'] ?? []),
                'accepted' => false,
                'model' => $model,
                'created_at' => $now,
            ];
            if ($version['summary'] === '') {
                throw new \InvalidArgumentException('The interpretation is missing a summary.');
            }
            $version['hash'] = Support::stableRecordHash($version);
            $state['versions'][] = $version;

            $state['ideas'][$ideaIndex]['current_version_id'] = $version['id'];
            $state['ideas'][$ideaIndex]['status'] = 'interpreted';
            $state['ideas'][$ideaIndex]['updated_at'] = $now;
            $state['ideas'][$ideaIndex]['hash'] = Support::stableRecordHash($state['ideas'][$ideaIndex]);

            foreach ($this->normaliseClaims($analysis['claims'] ?? []) as $claim) {
                $record = [
                    'id' => Support::id('claim'),
                    'idea_id' => $ideaId,
                    'version_id' => $version['id'],
                    'type' => $claim['type'],
                    'text' => $claim['text'],
                    'status' => 'unverified',
                    'created_at' => $now,
                ];
                $record['hash'] = Support::stableRecordHash($record);
                $state['claims'][] = $record;
            }

            $state['activities'][] = $this->activity($ideaId, 'idea.interpreted', 'A new interpretation was generated for review.', [
                'version_id' => $version['id'],
                'model' => $model,
            ]);

            return $version;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function setVersionAccepted(string $ideaId, string $versionId, bool $accepted): array
    {
        return $this->store->mutate(function (array &$state) use ($ideaId, $versionId, $accepted): array {
            $versionIndex = $this->findIndexById($state['versions'] ?? [], $versionId);
            if ($versionIndex === null || ($state['versions'][$versionIndex]['idea_id'] ?? null) !== $ideaId) {
                throw new \RuntimeException('Idea version not found.');
            }

            foreach ($state['versions'] as &$version) {
                if (($version['idea_id'] ?? null) === $ideaId) {
                    $version['accepted'] = false;
                }
            }
            unset($version);

            $state['versions'][$versionIndex]['accepted'] = $accepted;
            $ideaIndex = $this->findIndexById($state['ideas'] ?? [], $ideaId);
            if ($ideaIndex !== null && $accepted) {
                $state['ideas'][$ideaIndex]['current_version_id'] = $versionId;
                $state['ideas'][$ideaIndex]['updated_at'] = Support::now();
                $state['ideas'][$ideaIndex]['hash'] = Support::stableRecordHash($state['ideas'][$ideaIndex]);
            }

            $state['activities'][] = $this->activity(
                $ideaId,
                $accepted ? 'interpretation.accepted' : 'interpretation.unaccepted',
                $accepted ? 'The interpretation was accepted by the user.' : 'The interpretation was marked unaccepted.',
                ['version_id' => $versionId]
            );

            return $state['versions'][$versionIndex];
        });
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function addResearchRun(string $ideaId, string $query, array $candidates, array $errors = []): array
    {
        return $this->store->mutate(function (array &$state) use ($ideaId, $query, $candidates, $errors): array {
            if ($this->findById($state['ideas'] ?? [], $ideaId) === null) {
                throw new \RuntimeException('Idea not found.');
            }

            $record = [
                'id' => Support::id('run'),
                'idea_id' => $ideaId,
                'query' => Support::cleanText($query, 500),
                'candidate_count' => count($candidates),
                'candidates' => array_slice($candidates, 0, 50),
                'errors' => $errors,
                'created_at' => Support::now(),
            ];
            $record['hash'] = Support::stableRecordHash($record);
            $state['research_runs'][] = $record;
            $state['activities'][] = $this->activity($ideaId, 'research.completed', 'Prior-work search completed.', [
                'research_run_id' => $record['id'],
                'candidate_count' => count($candidates),
                'errors' => $errors,
            ]);
            return $record;
        });
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function saveSource(string $ideaId, array $candidate): array
    {
        return $this->store->mutate(function (array &$state) use ($ideaId, $candidate): array {
            if ($this->findById($state['ideas'] ?? [], $ideaId) === null) {
                throw new \RuntimeException('Idea not found.');
            }

            $record = [
                'id' => Support::id('src'),
                'idea_id' => $ideaId,
                'source_type' => $this->allowedValue($candidate['source_type'] ?? 'web', ['paper', 'repository', 'standard', 'dataset', 'web', 'uploaded_pdf'], 'web'),
                'provider' => Support::cleanText($candidate['provider'] ?? 'manual', 80),
                'title' => Support::cleanText($candidate['title'] ?? '', 600),
                'authors' => $this->cleanStringList($candidate['authors'] ?? [], 40, 240),
                'year' => is_numeric($candidate['year'] ?? null) ? (int) $candidate['year'] : null,
                'url' => $this->safeUrl($candidate['url'] ?? ''),
                'identifier' => Support::cleanText($candidate['identifier'] ?? '', 300),
                'summary' => Support::cleanText($candidate['summary'] ?? '', 5000),
                'metrics' => is_array($candidate['metrics'] ?? null) ? $candidate['metrics'] : [],
                'trust_status' => 'candidate',
                'source_status' => 'saved',
                'file_path' => null,
                'file_sha256' => null,
                'retrieved_at' => Support::now(),
                'created_at' => Support::now(),
            ];
            if ($record['title'] === '') {
                throw new \InvalidArgumentException('A source title is required.');
            }
            $record['hash'] = Support::stableRecordHash($record);

            foreach ($state['sources'] ?? [] as $existing) {
                if (!is_array($existing) || ($existing['idea_id'] ?? null) !== $ideaId) {
                    continue;
                }
                if (($existing['hash'] ?? null) === $record['hash']) {
                    return $existing;
                }
                if ($record['identifier'] !== '' && ($existing['identifier'] ?? '') === $record['identifier']) {
                    return $existing;
                }
            }

            $state['sources'][] = $record;
            $state['activities'][] = $this->activity($ideaId, 'source.saved', 'A candidate source was saved.', [
                'source_id' => $record['id'],
                'provider' => $record['provider'],
                'source_hash' => $record['hash'],
            ]);
            return $record;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function saveUploadedPdf(string $ideaId, string $title, string $temporaryPath, string $originalFilename): array
    {
        if (!is_file($temporaryPath)) {
            throw new \InvalidArgumentException('Uploaded PDF was not received.');
        }

        $size = filesize($temporaryPath);
        if ($size === false || $size <= 0 || $size > 25 * 1024 * 1024) {
            throw new \InvalidArgumentException('PDF must be between 1 byte and 25 MB.');
        }

        $header = file_get_contents($temporaryPath, false, null, 0, 5);
        if ($header !== '%PDF-') {
            throw new \InvalidArgumentException('The uploaded file does not appear to be a PDF.');
        }

        $sha = hash_file('sha256', $temporaryPath);
        if ($sha === false) {
            throw new \RuntimeException('Unable to hash uploaded PDF.');
        }

        $directory = Support::rootPath('data/sources');
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create source storage directory.');
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $sha . '.pdf';
        if (!is_file($destination) && !move_uploaded_file($temporaryPath, $destination)) {
            if (!rename($temporaryPath, $destination)) {
                throw new \RuntimeException('Unable to store uploaded PDF.');
            }
        }
        @chmod($destination, 0660);

        return $this->store->mutate(function (array &$state) use ($ideaId, $title, $originalFilename, $sha, $destination, $size): array {
            if ($this->findById($state['ideas'] ?? [], $ideaId) === null) {
                throw new \RuntimeException('Idea not found.');
            }

            foreach ($state['sources'] ?? [] as $existing) {
                if (is_array($existing) && ($existing['idea_id'] ?? null) === $ideaId && ($existing['file_sha256'] ?? null) === $sha) {
                    return $existing;
                }
            }

            $record = [
                'id' => Support::id('src'),
                'idea_id' => $ideaId,
                'source_type' => 'uploaded_pdf',
                'provider' => 'user_upload',
                'title' => Support::cleanText($title !== '' ? $title : $originalFilename, 600),
                'authors' => [],
                'year' => null,
                'url' => '',
                'identifier' => '',
                'summary' => 'Exact PDF copy uploaded by the user. Full-text extraction is a later milestone.',
                'metrics' => ['bytes' => $size, 'original_filename' => Support::cleanText($originalFilename, 255)],
                'trust_status' => 'candidate',
                'source_status' => 'saved',
                'file_path' => substr($destination, strlen(Support::rootPath()) + 1),
                'file_sha256' => $sha,
                'retrieved_at' => Support::now(),
                'created_at' => Support::now(),
            ];
            $record['hash'] = Support::stableRecordHash($record);
            $state['sources'][] = $record;
            $state['activities'][] = $this->activity($ideaId, 'source.pdf_uploaded', 'An exact PDF copy was saved and hashed.', [
                'source_id' => $record['id'],
                'sha256' => $sha,
                'bytes' => $size,
            ]);
            return $record;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function setSourceTrust(string $ideaId, string $sourceId, string $trustStatus): array
    {
        $allowed = ['candidate', 'trusted', 'contested', 'superseded', 'retracted', 'excluded'];
        if (!in_array($trustStatus, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported source trust status.');
        }

        return $this->store->mutate(function (array &$state) use ($ideaId, $sourceId, $trustStatus): array {
            $sourceIndex = $this->findIndexById($state['sources'] ?? [], $sourceId);
            if ($sourceIndex === null || ($state['sources'][$sourceIndex]['idea_id'] ?? null) !== $ideaId) {
                throw new \RuntimeException('Source not found.');
            }
            $state['sources'][$sourceIndex]['trust_status'] = $trustStatus;
            $state['sources'][$sourceIndex]['updated_at'] = Support::now();
            $state['sources'][$sourceIndex]['hash'] = Support::stableRecordHash($state['sources'][$sourceIndex]);
            $state['activities'][] = $this->activity($ideaId, 'source.trust_changed', 'Source trust status changed.', [
                'source_id' => $sourceId,
                'trust_status' => $trustStatus,
            ]);
            return $state['sources'][$sourceIndex];
        });
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function addChallenges(string $ideaId, array $items, ?string $model): array
    {
        return $this->store->mutate(function (array &$state) use ($ideaId, $items, $model): array {
            if ($this->findById($state['ideas'] ?? [], $ideaId) === null) {
                throw new \RuntimeException('Idea not found.');
            }

            $saved = [];
            foreach (array_slice($items, 0, 20) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = Support::cleanText($item['challenge'] ?? $item['text'] ?? '', 5000);
                if ($text === '') {
                    continue;
                }
                $record = [
                    'id' => Support::id('challenge'),
                    'idea_id' => $ideaId,
                    'claim' => Support::cleanText($item['claim'] ?? '', 3000),
                    'category' => $this->allowedValue($item['category'] ?? 'evidence', [
                        'meaning', 'prior_art', 'mechanism', 'evidence', 'practicality', 'adversarial', 'alternative', 'falsification', 'user_value', 'cost',
                    ], 'evidence'),
                    'challenge' => $text,
                    'severity' => $this->allowedValue($item['severity'] ?? 'material', ['minor', 'material', 'major', 'potentially_fatal'], 'material'),
                    'evidence_status' => $this->allowedValue($item['evidence_status'] ?? 'logical_unverified', [
                        'evidence_backed', 'logical_unverified', 'speculative', 'resolved',
                    ], 'logical_unverified'),
                    'why_it_matters' => Support::cleanText($item['why_it_matters'] ?? '', 4000),
                    'resolution_test' => Support::cleanText($item['resolution_test'] ?? '', 4000),
                    'status' => 'open',
                    'model' => $model,
                    'created_at' => Support::now(),
                ];
                $record['hash'] = Support::stableRecordHash($record);
                $state['challenges'][] = $record;
                $saved[] = $record;
            }

            $state['activities'][] = $this->activity($ideaId, 'challenge.generated', 'The idea was subjected to structured pushback.', [
                'challenge_count' => count($saved),
                'model' => $model,
            ]);
            return $saved;
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findSource(string $sourceId): ?array
    {
        $state = $this->store->read();
        return $this->findById($state['sources'] ?? [], $sourceId);
    }

    /**
     * @param mixed $rows
     * @return array<string,mixed>|null
     */
    private function findById(mixed $rows, string $id): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param mixed $rows
     */
    private function findIndexById(mixed $rows, string $id): ?int
    {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $index => $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return (int) $index;
            }
        }
        return null;
    }

    /**
     * @param mixed $values
     * @return array<int,string>
     */
    private function cleanStringList(mixed $values, int $limit = 20, int $itemMax = 1000): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            $clean = Support::cleanText($value, $itemMax);
            if ($clean !== '') {
                $result[] = $clean;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * @param mixed $claims
     * @return array<int,array{type:string,text:string}>
     */
    private function normaliseClaims(mixed $claims): array
    {
        if (!is_array($claims)) {
            return [];
        }
        $allowed = ['problem', 'mechanism', 'novelty', 'feasibility', 'user_value', 'security', 'economic', 'other'];
        $result = [];
        foreach (array_slice($claims, 0, 30) as $claim) {
            if (is_string($claim)) {
                $text = Support::cleanText($claim, 2000);
                if ($text !== '') {
                    $result[] = ['type' => 'other', 'text' => $text];
                }
                continue;
            }
            if (!is_array($claim)) {
                continue;
            }
            $text = Support::cleanText($claim['text'] ?? '', 2000);
            if ($text === '') {
                continue;
            }
            $type = $this->allowedValue($claim['type'] ?? 'other', $allowed, 'other');
            $result[] = ['type' => $type, 'text' => $text];
        }
        return $result;
    }

    private function allowedValue(mixed $value, array $allowed, string $default): string
    {
        $value = Support::cleanText($value, 80);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function safeUrl(mixed $value): string
    {
        $url = Support::cleanText($value, 2000);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https'], true)) {
            return '';
        }
        return $url;
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function activity(string $ideaId, string $type, string $message, array $details = []): array
    {
        return [
            'id' => Support::id('act'),
            'idea_id' => $ideaId,
            'type' => $type,
            'message' => $message,
            'details' => $details,
            'created_at' => Support::now(),
        ];
    }
}
