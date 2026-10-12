<?php
declare(strict_types=1);

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/IdeaService.php';
require_once __DIR__ . '/ResearchService.php';
require_once __DIR__ . '/OpenAIService.php';

\Researched\Support::loadEnv();
