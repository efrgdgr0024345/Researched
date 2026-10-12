<?php
declare(strict_types=1);

use Researched\HttpClient;
use Researched\IdeaService;
use Researched\JsonStore;
use Researched\OpenAIService;
use Researched\ResearchService;
use Researched\Support;

require dirname(__DIR__) . '/src/bootstrap.php';

session_name('researched_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$password = Support::env('APP_PASSWORD');
$requiresLogin = $password !== null;
$isAuthenticated = !$requiresLogin || (bool) ($_SESSION['authenticated'] ?? false);

/** @return never */
function apiError(string $message, int $status = 400, array $details = []): never
{
    Support::jsonResponse(['ok' => false, 'error' => $message, 'details' => $details], $status);
}

function requireCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        apiError('The request security token is missing or invalid. Refresh the page and try again.', 419);
    }
}

$api = Support::cleanText($_GET['api'] ?? '', 80);

if ($api !== '') {
    try {
        if ($api === 'health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            Support::jsonResponse([
                'ok' => true,
                'app' => 'Researched Idea Workbench',
                'version' => '0.1.0',
                'php' => PHP_VERSION,
                'openai_configured' => Support::env('OPENAI_API_KEY') !== null,
                'authenticated' => $isAuthenticated,
                'time' => Support::now(),
            ]);
        }

        if ($api === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            if (!$requiresLogin) {
                $_SESSION['authenticated'] = true;
                Support::jsonResponse(['ok' => true]);
            }
            $body = Support::jsonBody();
            $provided = Support::cleanText($body['password'] ?? '', 1000);
            if ($provided === '' || !hash_equals((string) $password, $provided)) {
                usleep(300000);
                apiError('Incorrect password.', 401);
            }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            Support::jsonResponse(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        }

        if (!$isAuthenticated) {
            apiError('Authentication is required.', 401);
        }

        if ($api === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            $_SESSION = [];
            session_destroy();
            Support::jsonResponse(['ok' => true]);
        }

        $store = new JsonStore();
        $ideas = new IdeaService($store);
        $http = new HttpClient();
        $research = new ResearchService($http);
        $openai = new OpenAIService($http);

        if ($api === 'ideas') {
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                Support::jsonResponse(['ok' => true, 'ideas' => $ideas->listIdeas()]);
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                requireCsrf();
                $body = Support::jsonBody();
                $idea = $ideas->createIdea(
                    Support::cleanText($body['title'] ?? '', 160),
                    is_scalar($body['original_text'] ?? null) ? (string) $body['original_text'] : ''
                );
                Support::jsonResponse(['ok' => true, 'idea' => $idea], 201);
            }
        }

        if ($api === 'idea' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $ideaId = Support::cleanText($_GET['id'] ?? '', 100);
            if ($ideaId === '') {
                apiError('An idea ID is required.', 422);
            }
            Support::jsonResponse(['ok' => true, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
        }

        if ($api === 'interpret' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $bundle = $ideas->getBundle($ideaId);
            $result = $openai->interpret($bundle);
            $version = $ideas->addInterpretation($ideaId, $result['analysis'], $result['model']);
            Support::jsonResponse([
                'ok' => true,
                'version' => $version,
                'mode' => $result['mode'],
                'bundle' => $ideas->getBundle($ideaId),
            ]);
        }


        if ($api === 'manual-interpretation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $lineList = static function (mixed $value): array {
                if (is_array($value)) {
                    return array_values(array_filter(array_map(static fn (mixed $item): string => Support::cleanText($item, 2000), $value)));
                }
                $text = Support::cleanText($value, 20000);
                return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
            };
            $claimLines = $lineList($body['claims'] ?? []);
            $analysis = [
                'summary' => Support::cleanText($body['summary'] ?? '', 12000),
                'problem' => Support::cleanText($body['problem'] ?? '', 6000),
                'mechanism' => Support::cleanText($body['mechanism'] ?? '', 6000),
                'intended_result' => Support::cleanText($body['intended_result'] ?? '', 6000),
                'assumptions' => $lineList($body['assumptions'] ?? []),
                'ambiguities' => $lineList($body['ambiguities'] ?? []),
                'success_criteria' => $lineList($body['success_criteria'] ?? []),
                'failure_criteria' => $lineList($body['failure_criteria'] ?? []),
                'claims' => array_map(static fn (string $claim): array => ['type' => 'other', 'text' => $claim], $claimLines),
            ];
            $version = $ideas->addInterpretation($ideaId, $analysis, 'user');
            Support::jsonResponse(['ok' => true, 'version' => $version, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($api === 'accept-version' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $versionId = Support::cleanText($body['version_id'] ?? '', 100);
            $accepted = filter_var($body['accepted'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
            $version = $ideas->setVersionAccepted($ideaId, $versionId, $accepted);
            Support::jsonResponse(['ok' => true, 'version' => $version, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($api === 'research' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $query = Support::cleanText($body['query'] ?? '', 500);
            $perProvider = (int) ($body['per_provider'] ?? 6);
            $ideas->getBundle($ideaId);
            $result = $research->search($query, $perProvider);
            $run = $ideas->addResearchRun($ideaId, $result['query'], $result['candidates'], $result['errors']);
            Support::jsonResponse(['ok' => true, 'run' => $run, 'result' => $result]);
        }

        if ($api === 'save-source' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $candidate = is_array($body['candidate'] ?? null) ? $body['candidate'] : [];
            $source = $ideas->saveSource($ideaId, $candidate);
            Support::jsonResponse(['ok' => true, 'source' => $source, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($api === 'source-trust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $sourceId = Support::cleanText($body['source_id'] ?? '', 100);
            $trustStatus = Support::cleanText($body['trust_status'] ?? '', 40);
            $source = $ideas->setSourceTrust($ideaId, $sourceId, $trustStatus);
            Support::jsonResponse(['ok' => true, 'source' => $source, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($api === 'upload-source' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $ideaId = Support::cleanText($_POST['idea_id'] ?? '', 100);
            $title = Support::cleanText($_POST['title'] ?? '', 600);
            $file = $_FILES['pdf'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                apiError('Choose a PDF file to upload.', 422);
            }
            $source = $ideas->saveUploadedPdf(
                $ideaId,
                $title,
                (string) ($file['tmp_name'] ?? ''),
                (string) ($file['name'] ?? 'source.pdf')
            );
            Support::jsonResponse(['ok' => true, 'source' => $source, 'bundle' => $ideas->getBundle($ideaId)]);
        }

        if ($api === 'challenge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = Support::jsonBody();
            $ideaId = Support::cleanText($body['idea_id'] ?? '', 100);
            $bundle = $ideas->getBundle($ideaId);
            $result = $openai->challenge($bundle);
            $saved = $ideas->addChallenges($ideaId, $result['challenges'], $result['model']);
            Support::jsonResponse([
                'ok' => true,
                'challenges' => $saved,
                'mode' => $result['mode'],
                'bundle' => $ideas->getBundle($ideaId),
            ]);
        }

        apiError('Unknown API route.', 404);
    } catch (InvalidArgumentException $error) {
        apiError($error->getMessage(), 422);
    } catch (RuntimeException $error) {
        $status = str_contains(strtolower($error->getMessage()), 'not found') ? 404 : 400;
        apiError($error->getMessage(), $status);
    } catch (Throwable $error) {
        error_log($error->__toString());
        apiError('An unexpected server error occurred.', 500);
    }
}

if (isset($_GET['download_source'])) {
    if (!$isAuthenticated) {
        http_response_code(401);
        exit('Authentication required.');
    }
    try {
        $ideas = new IdeaService(new JsonStore());
        $source = $ideas->findSource(Support::cleanText($_GET['download_source'], 100));
        if ($source === null || ($source['source_type'] ?? '') !== 'uploaded_pdf' || !is_string($source['file_path'] ?? null)) {
            throw new RuntimeException('Source not found.');
        }
        $base = realpath(Support::rootPath('data/sources'));
        $path = realpath(Support::rootPath($source['file_path']));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new RuntimeException('Stored source file is unavailable.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="source-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $source['id']) . '.pdf"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    } catch (Throwable $error) {
        http_response_code(404);
        exit('Source not found.');
    }
}

$csrf = htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
$authenticated = $isAuthenticated ? '1' : '0';
$openaiConfigured = Support::env('OPENAI_API_KEY') !== null ? '1' : '0';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07130f">
    <meta name="description" content="A rigorous mobile-first workbench for preserving, researching, challenging, and testing ideas.">
    <title>Researched — Idea Workbench</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body data-csrf="<?= $csrf ?>" data-authenticated="<?= $authenticated ?>" data-openai="<?= $openaiConfigured ?>">
<?php if (!$isAuthenticated): ?>
    <main class="login-shell">
        <section class="login-card">
            <div class="brand-mark" aria-hidden="true">R</div>
            <p class="eyebrow">Evidence before enthusiasm</p>
            <h1>Researched</h1>
            <p>Enter the workspace password to open the idea case files.</p>
            <form id="login-form" class="stack-form">
                <label for="login-password">Workspace password</label>
                <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                <button class="button primary" type="submit">Open workbench</button>
                <p id="login-error" class="form-error" role="alert"></p>
            </form>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <header class="app-header">
            <div class="brand-block">
                <div class="brand-mark" aria-hidden="true">R</div>
                <div>
                    <p class="eyebrow">Evidence before enthusiasm</p>
                    <h1>Researched</h1>
                </div>
            </div>
            <div class="header-actions">
                <span class="status-pill <?= $openaiConfigured === '1' ? 'online' : 'local' ?>">
                    <?= $openaiConfigured === '1' ? 'AI connected' : 'Local fallback' ?>
                </span>
                <?php if ($requiresLogin): ?>
                    <button id="logout-button" class="icon-button" type="button" aria-label="Sign out" title="Sign out">↪</button>
                <?php endif; ?>
            </div>
        </header>

        <aside class="idea-rail" aria-label="Idea case files">
            <div class="rail-heading">
                <div>
                    <p class="eyebrow">Case files</p>
                    <h2>Your ideas</h2>
                </div>
                <button id="new-idea-button" class="button small primary" type="button">New idea</button>
            </div>
            <div id="idea-list" class="idea-list" aria-live="polite"></div>
        </aside>

        <main class="workspace">
            <section id="empty-state" class="empty-state">
                <div class="empty-symbol">?</div>
                <p class="eyebrow">Start with the thought as it exists</p>
                <h2>Preserve an idea before the system changes it.</h2>
                <p>The workbench keeps your original wording, creates a separate interpretation, searches for prior work, saves exact sources, and actively looks for reasons the idea could fail.</p>
                <button class="button primary" type="button" data-action="new-idea">Capture the first idea</button>
            </section>

            <section id="case-workspace" class="case-workspace" hidden>
                <div class="case-header">
                    <div>
                        <p id="case-status" class="eyebrow">Captured</p>
                        <h2 id="case-title">Untitled idea</h2>
                    </div>
                    <div id="case-counts" class="count-row"></div>
                </div>

                <nav class="tab-bar" aria-label="Idea sections">
                    <button class="tab-button active" type="button" data-tab="idea">Idea</button>
                    <button class="tab-button" type="button" data-tab="prior-work">Prior work</button>
                    <button class="tab-button" type="button" data-tab="sources">Sources</button>
                    <button class="tab-button" type="button" data-tab="challenges">Challenges</button>
                    <button class="tab-button" type="button" data-tab="log">Log</button>
                </nav>

                <section class="tab-panel active" data-panel="idea">
                    <article class="panel-card original-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Immutable record</p>
                                <h3>Your original wording</h3>
                            </div>
                            <span id="original-hash" class="hash-chip" title="Record hash"></span>
                        </div>
                        <p id="original-text" class="preserved-text"></p>
                    </article>

                    <article class="panel-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Understanding contract</p>
                                <h3>What the system thinks you mean</h3>
                            </div>
                            <div class="action-row compact-actions"><button id="edit-interpretation-button" class="button ghost" type="button">Edit wording</button><button id="interpret-button" class="button secondary" type="button">Reword and test understanding</button></div>
                        </div>
                        <div id="interpretation-content" class="content-stack"></div>
                    </article>

                    <article class="panel-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Claims to investigate</p>
                                <h3>What must be true</h3>
                            </div>
                        </div>
                        <div id="claims-list" class="card-list"></div>
                    </article>
                </section>

                <section class="tab-panel" data-panel="prior-work">
                    <article class="panel-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">OpenAlex · Crossref · GitHub</p>
                                <h3>Search existing work</h3>
                            </div>
                        </div>
                        <form id="research-form" class="search-form">
                            <label class="sr-only" for="research-query">Research query</label>
                            <input id="research-query" name="query" type="search" placeholder="Mechanism, problem, academic term, or competing method" required>
                            <button class="button primary" type="submit">Search</button>
                        </form>
                        <p class="fine-print">A search result is only a candidate. Saving it does not make it trusted evidence.</p>
                    </article>
                    <div id="research-summary" class="notice" hidden></div>
                    <div id="candidate-list" class="card-list"></div>
                </section>

                <section class="tab-panel" data-panel="sources">
                    <article class="panel-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Exact source preservation</p>
                                <h3>Upload a research paper</h3>
                            </div>
                        </div>
                        <form id="upload-form" class="upload-form" enctype="multipart/form-data">
                            <label for="source-title">Source title</label>
                            <input id="source-title" name="title" type="text" maxlength="600" placeholder="Paper title (optional)">
                            <label for="source-pdf">PDF, maximum 25 MB</label>
                            <input id="source-pdf" name="pdf" type="file" accept="application/pdf,.pdf" required>
                            <button class="button secondary" type="submit">Hash and preserve PDF</button>
                        </form>
                    </article>
                    <div id="source-list" class="card-list"></div>
                </section>

                <section class="tab-panel" data-panel="challenges">
                    <article class="panel-card challenge-lead">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Structured opposition</p>
                                <h3>Try seriously to disprove the idea</h3>
                            </div>
                            <button id="challenge-button" class="button danger" type="button">Challenge this idea</button>
                        </div>
                        <p>The critic must attack the strongest reasonable version, distinguish evidence from logic, and propose a test that could resolve each objection.</p>
                    </article>
                    <div id="strongest-challenge"></div>
                    <div id="challenge-list" class="card-list"></div>
                </section>

                <section class="tab-panel" data-panel="log">
                    <article class="panel-card">
                        <div class="card-heading">
                            <div>
                                <p class="eyebrow">Audit trail</p>
                                <h3>How the case changed</h3>
                            </div>
                        </div>
                        <div id="activity-list" class="timeline"></div>
                    </article>
                </section>
            </section>
        </main>
    </div>

    <dialog id="new-idea-dialog" class="modal">
        <form id="new-idea-form" method="dialog" class="modal-card">
            <div class="card-heading">
                <div>
                    <p class="eyebrow">Immutable first record</p>
                    <h2>Capture an idea</h2>
                </div>
                <button class="icon-button" type="button" data-action="close-dialog" aria-label="Close">×</button>
            </div>
            <label for="new-title">Short title</label>
            <input id="new-title" name="title" type="text" maxlength="160" placeholder="Optional — generated from the idea if blank">
            <label for="new-original">Original idea</label>
            <textarea id="new-original" name="original_text" rows="9" maxlength="30000" placeholder="Write it in your own words. The original will be preserved exactly as a separate version." required></textarea>
            <div class="modal-actions">
                <button class="button ghost" type="button" data-action="close-dialog">Cancel</button>
                <button class="button primary" type="submit">Create case file</button>
            </div>
        </form>
    </dialog>


    <dialog id="edit-interpretation-dialog" class="modal">
        <form id="edit-interpretation-form" method="dialog" class="modal-card long-form">
            <div class="card-heading">
                <div>
                    <p class="eyebrow">User-controlled meaning</p>
                    <h2>Create a corrected interpretation</h2>
                </div>
                <button class="icon-button" type="button" data-action="close-edit-dialog" aria-label="Close">×</button>
            </div>
            <p class="fine-print">This creates a new version. It never overwrites the original wording or an earlier interpretation.</p>
            <label for="edit-summary">Precise restatement</label>
            <textarea id="edit-summary" name="summary" rows="5" required></textarea>
            <label for="edit-problem">Problem</label>
            <textarea id="edit-problem" name="problem" rows="3"></textarea>
            <label for="edit-mechanism">Proposed mechanism</label>
            <textarea id="edit-mechanism" name="mechanism" rows="3"></textarea>
            <label for="edit-result">Intended result</label>
            <textarea id="edit-result" name="intended_result" rows="3"></textarea>
            <div class="two-column-form">
                <div><label for="edit-assumptions">Assumptions — one per line</label><textarea id="edit-assumptions" name="assumptions" rows="5"></textarea></div>
                <div><label for="edit-ambiguities">Ambiguities — one per line</label><textarea id="edit-ambiguities" name="ambiguities" rows="5"></textarea></div>
                <div><label for="edit-success">Success criteria — one per line</label><textarea id="edit-success" name="success_criteria" rows="5"></textarea></div>
                <div><label for="edit-failure">Failure criteria — one per line</label><textarea id="edit-failure" name="failure_criteria" rows="5"></textarea></div>
            </div>
            <label for="edit-claims">Claims to investigate — one per line</label>
            <textarea id="edit-claims" name="claims" rows="5"></textarea>
            <div class="modal-actions">
                <button class="button ghost" type="button" data-action="close-edit-dialog">Cancel</button>
                <button class="button primary" type="submit">Save as new version</button>
            </div>
        </form>
    </dialog>

    <div id="loading-overlay" class="loading-overlay" hidden>
        <div class="spinner" aria-hidden="true"></div>
        <p id="loading-message">Working…</p>
    </div>
    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>
<?php endif; ?>
<script src="assets/app.js?v=1" defer></script>
</body>
</html>
