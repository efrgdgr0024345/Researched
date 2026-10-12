<?php
declare(strict_types=1);

namespace Researched;

final class OpenAIService
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    public function isConfigured(): bool
    {
        return Support::env('OPENAI_API_KEY') !== null;
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array{analysis:array<string,mixed>,model:?string,mode:string}
     */
    public function interpret(array $bundle): array
    {
        if (!$this->isConfigured()) {
            return [
                'analysis' => $this->fallbackInterpretation($bundle),
                'model' => null,
                'mode' => 'deterministic_fallback',
            ];
        }

        $model = Support::env('OPENAI_MODEL_CHEAP', 'gpt-6-luna');
        $idea = $bundle['idea'] ?? [];
        $prompt = <<<'PROMPT'
You are the Understanding Agent for a rigorous idea-investigation system.
Preserve the user's meaning. Do not praise the idea, claim novelty, or invent evidence.
Restate the idea more precisely, expose ambiguity, and decompose it into testable claims.
Return JSON only with this exact shape:
{
  "summary": "one precise paragraph",
  "problem": "problem the idea attempts to solve",
  "mechanism": "how the proposed mechanism is supposed to work",
  "intended_result": "intended outcome",
  "assumptions": ["..."],
  "ambiguities": ["..."],
  "success_criteria": ["..."],
  "failure_criteria": ["..."],
  "claims": [{"type":"problem|mechanism|novelty|feasibility|user_value|security|economic|other","text":"..."}]
}
PROMPT;
        $input = $prompt . "\n\nIDEA TITLE:\n" . (string) ($idea['title'] ?? '') . "\n\nORIGINAL IDEA:\n" . (string) ($idea['original_text'] ?? '');
        $analysis = $this->requestJson($model ?? 'gpt-6-luna', $input, 'low');
        return ['analysis' => $analysis, 'model' => $model, 'mode' => 'openai'];
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array{challenges:array<int,array<string,mixed>>,model:?string,mode:string}
     */
    public function challenge(array $bundle): array
    {
        if (!$this->isConfigured()) {
            return [
                'challenges' => $this->fallbackChallenges($bundle),
                'model' => null,
                'mode' => 'deterministic_fallback',
            ];
        }

        $model = Support::env('OPENAI_MODEL_STANDARD', 'gpt-6.1-sol');
        $idea = $bundle['idea'] ?? [];
        $version = $bundle['current_version'] ?? [];
        $sources = $bundle['sources'] ?? [];
        $sourceSummary = array_map(static fn (array $source): array => [
            'title' => $source['title'] ?? '',
            'type' => $source['source_type'] ?? '',
            'trust_status' => $source['trust_status'] ?? '',
            'summary' => $source['summary'] ?? '',
        ], array_slice(is_array($sources) ? $sources : [], 0, 20));

        $prompt = <<<'PROMPT'
You are an adversarial but fair Critical Reviewer. Challenge the strongest reasonable version of the idea, not a careless straw man.
Do not automatically agree. Do not manufacture sources or claim that an objection is evidence-backed unless the supplied saved sources directly support it.
Identify load-bearing weaknesses, prior-art risks, mechanism gaps, user-value assumptions, simpler alternatives, abuse cases, and falsification tests.
Return JSON only with this exact shape:
{
  "challenges": [
    {
      "claim": "claim under challenge",
      "category": "meaning|prior_art|mechanism|evidence|practicality|adversarial|alternative|falsification|user_value|cost",
      "challenge": "specific objection",
      "severity": "minor|material|major|potentially_fatal",
      "evidence_status": "evidence_backed|logical_unverified|speculative|resolved",
      "why_it_matters": "consequence if true",
      "resolution_test": "cheapest test or evidence that would resolve it"
    }
  ]
}
Produce 5 to 8 non-duplicative challenges, ordered by importance.
PROMPT;

        $input = $prompt
            . "\n\nORIGINAL IDEA:\n" . (string) ($idea['original_text'] ?? '')
            . "\n\nCURRENT INTERPRETATION:\n" . json_encode($version, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nSAVED SOURCES:\n" . json_encode($sourceSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = $this->requestJson($model ?? 'gpt-6.1-sol', $input, 'high');
        $challenges = is_array($decoded['challenges'] ?? null) ? $decoded['challenges'] : [];
        return ['challenges' => $challenges, 'model' => $model, 'mode' => 'openai'];
    }

    /**
     * @return array<string,mixed>
     */
    private function requestJson(string $model, string $input, string $effort): array
    {
        $key = Support::env('OPENAI_API_KEY');
        if ($key === null) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
            'input' => $input,
            'reasoning' => ['effort' => $effort],
            'max_output_tokens' => 5000,
            'store' => false,
        ];
        $response = $this->http->postJson('https://api.openai.com/v1/responses', $payload, [
            'Authorization' => 'Bearer ' . $key,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $snippet = Support::truncate(trim($response['body']), 1000);
            throw new \RuntimeException('OpenAI returned HTTP ' . $response['status'] . ($snippet !== '' ? ': ' . $snippet : ''));
        }

        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $text = $this->extractOutputText($data);
        $json = $this->extractJsonObject($text);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI returned an unexpected structured result.');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractOutputText(array $data): string
    {
        if (is_string($data['output_text'] ?? null) && trim($data['output_text']) !== '') {
            return $data['output_text'];
        }
        $parts = [];
        foreach ($data['output'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (is_array($content) && is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new \RuntimeException('OpenAI returned no text output.');
        }
        return $text;
    }

    private function extractJsonObject(string $text): string
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('OpenAI did not return a JSON object.');
        }
        return substr($trimmed, $start, $end - $start + 1);
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    private function fallbackInterpretation(array $bundle): array
    {
        $idea = $bundle['idea'] ?? [];
        $original = Support::cleanText($idea['original_text'] ?? '', 30000);
        $title = Support::cleanText($idea['title'] ?? 'Untitled idea', 160);
        $summary = "The current proposal is: " . $original;

        return [
            'summary' => $summary,
            'problem' => 'The specific problem has not yet been separated clearly from the proposed solution.',
            'mechanism' => 'The mechanism requires user confirmation or an OpenAI API key for a more precise interpretation.',
            'intended_result' => 'Clarify what a successful outcome would look like for “' . $title . '”.',
            'assumptions' => [
                'The stated problem exists in the form described.',
                'The proposed mechanism can affect the intended outcome.',
                'Existing work does not already solve the problem adequately.',
            ],
            'ambiguities' => [
                'Key terms and measurable success criteria are not yet defined.',
                'The boundary between the problem, mechanism, and desired result may be unclear.',
            ],
            'success_criteria' => [
                'The idea can be stated as testable claims.',
                'Prior work can be searched using precise terminology.',
                'At least one falsifiable experiment can be defined.',
            ],
            'failure_criteria' => [
                'A simpler existing system already solves the same problem sufficiently.',
                'The load-bearing mechanism cannot be tested or does not produce the intended effect.',
            ],
            'claims' => [
                ['type' => 'problem', 'text' => 'The problem described by the user is real and important enough to solve.'],
                ['type' => 'mechanism', 'text' => 'The proposed mechanism can produce the intended result.'],
                ['type' => 'novelty', 'text' => 'Existing work does not already implement the same important contribution.'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array<int,array<string,mixed>>
     */
    private function fallbackChallenges(array $bundle): array
    {
        $idea = $bundle['idea'] ?? [];
        $title = Support::cleanText($idea['title'] ?? 'the idea', 160);
        return [
            [
                'claim' => 'The system has correctly understood the proposal.',
                'category' => 'meaning',
                'challenge' => 'The current wording may combine the problem, mechanism, and desired result, causing research to target the wrong question.',
                'severity' => 'major',
                'evidence_status' => 'logical_unverified',
                'why_it_matters' => 'A precise but incorrect interpretation could produce an impressive investigation of a different idea.',
                'resolution_test' => 'Confirm a one-sentence statement of the problem, mechanism, and success criterion separately.',
            ],
            [
                'claim' => 'The important part of “' . $title . '” is new.',
                'category' => 'prior_art',
                'challenge' => 'The same concept may exist under older, academic, commercial, or adjacent terminology.',
                'severity' => 'major',
                'evidence_status' => 'logical_unverified',
                'why_it_matters' => 'Unfound prior work could remove the novelty claim or reveal reusable work and known failures.',
                'resolution_test' => 'Search multiple terminology families across papers, repositories, standards, and failed projects.',
            ],
            [
                'claim' => 'The proposed mechanism causes the intended outcome.',
                'category' => 'mechanism',
                'challenge' => 'The causal steps between the mechanism and the claimed benefit have not yet been demonstrated.',
                'severity' => 'potentially_fatal',
                'evidence_status' => 'logical_unverified',
                'why_it_matters' => 'The product could be implemented correctly while failing to create the expected value.',
                'resolution_test' => 'Identify the weakest causal step and test it with the smallest controlled prototype.',
            ],
            [
                'claim' => 'Users will prefer this approach.',
                'category' => 'user_value',
                'challenge' => 'Technical interest does not establish that users understand, trust, or repeatedly use the proposed workflow.',
                'severity' => 'material',
                'evidence_status' => 'speculative',
                'why_it_matters' => 'A technically sound project can fail because it adds more friction than the existing alternative.',
                'resolution_test' => 'Test the case-file workflow with representative users and compare it with a normal research report.',
            ],
            [
                'claim' => 'The proposed solution is justified by the problem.',
                'category' => 'alternative',
                'challenge' => 'A simpler existing method may solve most of the problem at substantially lower cost and complexity.',
                'severity' => 'major',
                'evidence_status' => 'logical_unverified',
                'why_it_matters' => 'Complexity must earn its place through a measurable improvement over the simplest credible baseline.',
                'resolution_test' => 'Define the strongest simple baseline and require the idea to outperform it on pre-agreed measures.',
            ],
            [
                'claim' => 'The idea is ready to become a project.',
                'category' => 'falsification',
                'challenge' => 'No explicit result has yet been stated that would cause the idea to be abandoned or materially revised.',
                'severity' => 'major',
                'evidence_status' => 'logical_unverified',
                'why_it_matters' => 'Without failure conditions, investigation can become an endless attempt to protect the idea.',
                'resolution_test' => 'Write at least one measurable failure threshold before building the full system.',
            ],
        ];
    }
}
