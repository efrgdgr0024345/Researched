# Architecture

## Current vertical slice

```text
Mobile browser
    │
    ▼
public/index.php
    ├── JSON API router + session/CSRF boundary
    └── static mobile UI
          │
          ▼
Domain services
    ├── IdeaService       versions, claims, sources, challenges, audit
    ├── ResearchService   OpenAlex, Crossref, GitHub candidate discovery
    ├── OpenAIService     interpretation and critical review + fallback
    ├── HttpClient        HTTPS requests using cURL or PHP streams
    └── JsonStore         atomic, locked, versioned local state
          │
          ▼
data/state.json + data/sources/<sha256>.pdf
```

## Why this simple architecture exists

The first job is to prove the workflow, not infrastructure complexity. The application therefore runs on PHP 8.1 with no dependency manager or external database. This makes it easy to execute in Codex Cloud, GitHub Actions, a local live Linux environment, or a normal PHP host.

The boundary is intentionally service-based so later milestones can replace `JsonStore` with PostgreSQL/object storage without rewriting the UI or domain behaviour.

## Domain invariants

- An original idea is created once and retained.
- Every reinterpretation is a new version.
- Generated interpretations begin unaccepted.
- Every discovered source begins as a candidate.
- Source trust changes require explicit user action and an audit event.
- Uploaded PDFs are content-addressed by SHA-256 and kept outside `public/`.
- Challenges state their evidential status and a resolution test.
- External provider failures are retained in the research-run record.

## API surface

The PHP front controller currently provides:

- `GET ?api=health`
- `GET|POST ?api=ideas`
- `GET ?api=idea&id=...`
- `POST ?api=interpret`
- `POST ?api=manual-interpretation`
- `POST ?api=accept-version`
- `POST ?api=research`
- `POST ?api=save-source`
- `POST ?api=source-trust`
- `POST ?api=upload-source`
- `POST ?api=challenge`
- `GET ?download_source=...`

All writes require a session CSRF token. Authentication is optional for local development and password-based for this single-user prototype.

## Planned evolution

When real use proves the need:

1. PostgreSQL for durable multi-user records and graph-like relationships.
2. S3-compatible object storage for exact source versions.
3. Background workers for paper extraction, repository inspection, and status monitoring.
4. Provider adapters and job budgets for model/tool routing.
5. Structured claim, passage, evidence, conclusion, and test records.
6. OAuth identity and per-project permission controls.

No migration should erase hashes, version history, source provenance, or audit events.
