<?php
declare(strict_types=1);

namespace Researched;

final class ResearchService
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @return array{query:string,candidates:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
     */
    public function search(string $query, int $perProvider = 6): array
    {
        $query = Support::cleanText($query, 500);
        if ($query === '') {
            throw new \InvalidArgumentException('A research query is required.');
        }
        $perProvider = max(1, min(10, $perProvider));

        $candidates = [];
        $errors = [];
        foreach ([
            'openalex' => fn (): array => $this->searchOpenAlex($query, $perProvider),
            'crossref' => fn (): array => $this->searchCrossref($query, $perProvider),
            'github' => fn (): array => $this->searchGitHub($query, $perProvider),
        ] as $provider => $searcher) {
            try {
                $candidates = array_merge($candidates, $searcher());
            } catch (\Throwable $error) {
                $errors[] = ['provider' => $provider, 'message' => $error->getMessage()];
            }
        }

        $candidates = $this->deduplicate($candidates);
        usort($candidates, static function (array $a, array $b): int {
            $scoreA = (int) ($a['metrics']['citations'] ?? $a['metrics']['stars'] ?? 0);
            $scoreB = (int) ($b['metrics']['citations'] ?? $b['metrics']['stars'] ?? 0);
            return $scoreB <=> $scoreA;
        });

        return ['query' => $query, 'candidates' => $candidates, 'errors' => $errors];
    }

    /** @return array<int,array<string,mixed>> */
    private function searchOpenAlex(string $query, int $limit): array
    {
        $params = [
            'search' => $query,
            'per-page' => (string) $limit,
            'select' => 'id,doi,title,display_name,publication_year,type,authorships,primary_location,open_access,cited_by_count,is_retracted',
        ];
        $mailto = Support::env('OPENALEX_MAILTO');
        if ($mailto !== null) {
            $params['mailto'] = $mailto;
        }

        $response = $this->http->get('https://api.openalex.org/works?' . http_build_query($params));
        $this->assertSuccess($response, 'OpenAlex');
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $rows = is_array($data['results'] ?? null) ? $data['results'] : [];
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $authors = [];
            foreach ($row['authorships'] ?? [] as $authorship) {
                if (is_array($authorship) && is_array($authorship['author'] ?? null)) {
                    $name = Support::cleanText($authorship['author']['display_name'] ?? '', 240);
                    if ($name !== '') {
                        $authors[] = $name;
                    }
                }
            }
            $doi = Support::cleanText($row['doi'] ?? '', 300);
            $openUrl = '';
            if (is_array($row['primary_location'] ?? null)) {
                $openUrl = Support::cleanText($row['primary_location']['landing_page_url'] ?? '', 2000);
            }
            if ($openUrl === '' && $doi !== '') {
                $openUrl = $doi;
            }
            if ($openUrl === '') {
                $openUrl = Support::cleanText($row['id'] ?? '', 2000);
            }

            $results[] = [
                'candidate_id' => hash('sha256', 'openalex:' . ($row['id'] ?? '') . ':' . ($row['title'] ?? '')),
                'source_type' => 'paper',
                'provider' => 'OpenAlex',
                'title' => Support::cleanText($row['display_name'] ?? $row['title'] ?? 'Untitled work', 600),
                'authors' => array_slice($authors, 0, 20),
                'year' => is_numeric($row['publication_year'] ?? null) ? (int) $row['publication_year'] : null,
                'url' => $this->httpsOnly($openUrl),
                'identifier' => $doi !== '' ? $doi : Support::cleanText($row['id'] ?? '', 300),
                'summary' => 'Scholarly work discovered through OpenAlex. Inspect the abstract/full text before relying on it.',
                'metrics' => [
                    'citations' => (int) ($row['cited_by_count'] ?? 0),
                    'work_type' => Support::cleanText($row['type'] ?? '', 100),
                    'open_access' => is_array($row['open_access'] ?? null) ? (bool) ($row['open_access']['is_oa'] ?? false) : false,
                    'retracted' => (bool) ($row['is_retracted'] ?? false),
                ],
            ];
        }
        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    private function searchCrossref(string $query, int $limit): array
    {
        $params = [
            'query' => $query,
            'rows' => (string) $limit,
            'select' => 'DOI,title,author,published,URL,type,is-referenced-by-count,container-title,abstract',
        ];
        $mailto = Support::env('CROSSREF_MAILTO');
        if ($mailto !== null) {
            $params['mailto'] = $mailto;
        }

        $response = $this->http->get('https://api.crossref.org/works?' . http_build_query($params));
        $this->assertSuccess($response, 'Crossref');
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $rows = is_array($data['message']['items'] ?? null) ? $data['message']['items'] : [];
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = is_array($row['title'] ?? null) ? (string) ($row['title'][0] ?? '') : (string) ($row['title'] ?? '');
            if (trim($title) === '') {
                continue;
            }
            $authors = [];
            foreach ($row['author'] ?? [] as $author) {
                if (!is_array($author)) {
                    continue;
                }
                $name = trim(((string) ($author['given'] ?? '')) . ' ' . ((string) ($author['family'] ?? '')));
                if ($name !== '') {
                    $authors[] = $name;
                }
            }
            $year = null;
            $dateParts = $row['published']['date-parts'][0] ?? null;
            if (is_array($dateParts) && is_numeric($dateParts[0] ?? null)) {
                $year = (int) $dateParts[0];
            }
            $abstract = Support::cleanText(strip_tags((string) ($row['abstract'] ?? '')), 2000);
            $doi = Support::cleanText($row['DOI'] ?? '', 300);
            $url = Support::cleanText($row['URL'] ?? '', 2000);

            $results[] = [
                'candidate_id' => hash('sha256', 'crossref:' . $doi . ':' . $title),
                'source_type' => 'paper',
                'provider' => 'Crossref',
                'title' => Support::cleanText($title, 600),
                'authors' => array_slice($authors, 0, 20),
                'year' => $year,
                'url' => $this->httpsOnly($url),
                'identifier' => $doi !== '' ? 'https://doi.org/' . $doi : '',
                'summary' => $abstract !== '' ? $abstract : 'Publication metadata discovered through Crossref. Inspect the full paper before relying on it.',
                'metrics' => [
                    'citations' => (int) ($row['is-referenced-by-count'] ?? 0),
                    'work_type' => Support::cleanText($row['type'] ?? '', 100),
                    'container' => is_array($row['container-title'] ?? null) ? Support::cleanText($row['container-title'][0] ?? '', 300) : '',
                ],
            ];
        }
        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    private function searchGitHub(string $query, int $limit): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = Support::env('GITHUB_TOKEN');
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $params = [
            'q' => $query . ' in:name,description,readme',
            'sort' => 'stars',
            'order' => 'desc',
            'per_page' => (string) $limit,
        ];
        $response = $this->http->get('https://api.github.com/search/repositories?' . http_build_query($params), $headers);
        $this->assertSuccess($response, 'GitHub');
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fullName = Support::cleanText($row['full_name'] ?? '', 300);
            if ($fullName === '') {
                continue;
            }
            $results[] = [
                'candidate_id' => hash('sha256', 'github:' . $fullName . ':' . ($row['default_branch'] ?? '')),
                'source_type' => 'repository',
                'provider' => 'GitHub',
                'title' => $fullName,
                'authors' => [Support::cleanText($row['owner']['login'] ?? '', 200)],
                'year' => isset($row['created_at']) ? (int) substr((string) $row['created_at'], 0, 4) : null,
                'url' => $this->httpsOnly((string) ($row['html_url'] ?? '')),
                'identifier' => 'github:' . $fullName,
                'summary' => Support::cleanText($row['description'] ?? 'No repository description supplied.', 2000),
                'metrics' => [
                    'stars' => (int) ($row['stargazers_count'] ?? 0),
                    'forks' => (int) ($row['forks_count'] ?? 0),
                    'open_issues' => (int) ($row['open_issues_count'] ?? 0),
                    'language' => Support::cleanText($row['language'] ?? '', 100),
                    'archived' => (bool) ($row['archived'] ?? false),
                    'default_branch' => Support::cleanText($row['default_branch'] ?? '', 120),
                    'updated_at' => Support::cleanText($row['updated_at'] ?? '', 80),
                ],
            ];
        }
        return $results;
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>} $response
     */
    private function assertSuccess(array $response, string $provider): void
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $snippet = Support::truncate(trim($response['body']), 500);
            throw new \RuntimeException($provider . ' returned HTTP ' . $response['status'] . ($snippet !== '' ? ': ' . $snippet : ''));
        }
    }

    /** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
    private function deduplicate(array $candidates): array
    {
        $seen = [];
        $result = [];
        foreach ($candidates as $candidate) {
            $identifier = strtolower(trim((string) ($candidate['identifier'] ?? '')));
            $title = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($candidate['title'] ?? '')) ?? '');
            $key = $identifier !== '' ? $identifier : $title;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }
        return $result;
    }

    private function httpsOnly(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://doi.org/')) {
            $url = 'https://doi.org/' . substr($url, strlen('http://doi.org/'));
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }
        return $url;
    }
}
