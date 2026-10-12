<?php
declare(strict_types=1);

use Researched\HttpClient;
use Researched\IdeaService;
use Researched\JsonStore;
use Researched\OpenAIService;

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = [];
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "PASS: {$message}\n");
    }
};

$tempRoot = sys_get_temp_dir() . '/researched-tests-' . bin2hex(random_bytes(5));
if (!mkdir($tempRoot, 0770, true) && !is_dir($tempRoot)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    $store = new JsonStore($tempRoot . '/state.json');
    $service = new IdeaService($store);

    $original = "  An idea with deliberate leading space.\nA second line.  ";
    $idea = $service->createIdea('', $original);
    $assert(str_starts_with((string) $idea['id'], 'idea_'), 'An idea receives a stable prefixed ID.');
    $assert($idea['original_text'] === $original, 'The original wording is preserved rather than replaced by an interpretation.');
    $assert($idea['title'] !== '', 'A title is derived when the user leaves it blank.');

    $bundle = $service->getBundle((string) $idea['id']);
    $assert(count($bundle['versions']) === 1, 'The first case file contains one original version.');
    $assert(($bundle['current_version']['kind'] ?? null) === 'original', 'The original version is initially current.');
    $assert(($bundle['current_version']['accepted'] ?? false) === true, 'The original version begins as accepted.');

    putenv('OPENAI_API_KEY=');
    unset($_ENV['OPENAI_API_KEY']);
    $ai = new OpenAIService(new HttpClient(1));
    $interpretation = $ai->interpret($bundle);
    $assert($interpretation['mode'] === 'deterministic_fallback', 'The workbench remains usable without an OpenAI API key.');
    $version = $service->addInterpretation((string) $idea['id'], $interpretation['analysis'], $interpretation['model']);
    $assert(($version['kind'] ?? null) === 'interpretation', 'An interpretation is stored as a new version.');
    $assert(($version['accepted'] ?? true) === false, 'A generated interpretation is not silently accepted.');

    $accepted = $service->setVersionAccepted((string) $idea['id'], (string) $version['id'], true);
    $assert(($accepted['accepted'] ?? false) === true, 'The user can explicitly accept an interpretation.');
    $bundle = $service->getBundle((string) $idea['id']);
    $assert(($bundle['current_version']['id'] ?? null) === $version['id'], 'The accepted version becomes the current interpretation.');
    $assert(count($bundle['current_claims']) >= 1, 'Claims are linked to the current interpretation version.');

    $candidate = [
        'source_type' => 'paper',
        'provider' => 'test',
        'title' => 'A test research paper',
        'authors' => ['Researcher One'],
        'year' => 2026,
        'url' => 'https://example.test/paper',
        'identifier' => 'doi:10.0000/test',
        'summary' => 'A test source that is not automatically treated as truth.',
        'metrics' => ['citations' => 2],
    ];
    $source = $service->saveSource((string) $idea['id'], $candidate);
    $assert(($source['trust_status'] ?? null) === 'candidate', 'A newly saved source begins as a candidate.');
    $duplicate = $service->saveSource((string) $idea['id'], $candidate);
    $assert($duplicate['id'] === $source['id'], 'A source with the same stable identifier is not duplicated.');
    $trusted = $service->setSourceTrust((string) $idea['id'], (string) $source['id'], 'trusted');
    $assert(($trusted['trust_status'] ?? null) === 'trusted', 'The user can explicitly promote a source to trusted evidence.');

    $challengeResult = $ai->challenge($service->getBundle((string) $idea['id']));
    $savedChallenges = $service->addChallenges((string) $idea['id'], $challengeResult['challenges'], $challengeResult['model']);
    $assert(count($savedChallenges) >= 5, 'Fallback mode still produces structured, non-trivial pushback.');
    $assert(array_reduce($savedChallenges, static fn (bool $found, array $row): bool => $found || ($row['severity'] ?? '') === 'potentially_fatal', false), 'Pushback can identify a potentially fatal load-bearing weakness.');

    $finalBundle = $service->getBundle((string) $idea['id']);
    $assert(($finalBundle['counts']['trusted_sources'] ?? 0) === 1, 'Trusted source counts are computed from stored evidence state.');
    $assert(($finalBundle['counts']['open_challenges'] ?? 0) >= 5, 'Open challenge counts remain visible in the case file.');
    $assert(count($finalBundle['activities']) >= 6, 'Consequential changes produce an audit trail.');

    $state = json_decode((string) file_get_contents($tempRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(($state['schema_version'] ?? null) === 1, 'The JSON store writes an explicit schema version.');
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tempRoot);
}

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " of {$checks} checks failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll {$checks} checks passed.\n");
