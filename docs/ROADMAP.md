# Roadmap

Each milestone must preserve a working end-to-end application. Large rewrites are not milestones.

## 0.1 — Working case-file vertical slice

Status: implemented in the bootstrap pull request.

- Capture immutable original idea.
- Generate or manually correct an interpretation.
- Require explicit acceptance.
- Search OpenAlex, Crossref, and public GitHub repositories.
- Save candidate sources.
- Upload and SHA-256 hash exact PDFs.
- Manage user-controlled trust status.
- Generate structured challenges.
- Maintain activity history.
- Run without an API key.

## 0.2 — Claim-to-evidence records

- Add exact evidence statements linked to a source and claim.
- Record page, section, table, figure, code path, or line range.
- Distinguish quotation, source claim, user note, and system interpretation.
- Prevent “evidence-backed” challenge status without a stored evidence link.
- Add evidence comparison on mobile.

This is the first Codex Cloud task in `FIRST_CODEX_TASK.md`.

## 0.3 — Full source inspection

- Optional `pdftotext` extraction with visible fallback when unavailable.
- Page-preserving text segmentation.
- DOI and paper-version metadata.
- Exact repository commit snapshots and licence records.
- Build/test result storage for inspected repositories.
- Source review status: discovered, shortlisted, inspected, user-reviewed.

## 0.4 — Reproducible prior-work protocol

- Search matrix and terminology map.
- Inclusion and exclusion criteria.
- Candidate deduplication review.
- Found/screened/inspected/included flow.
- Search coverage and blind spots.
- Independent second-pass screening for load-bearing sources.
- Bounded novelty map instead of a global novelty score.

## 0.5 — Cross-checking and source health

- Support, contradict, qualify, reproduce, fail-to-reproduce, implement, supersede, correct, and retract relationships.
- Independence checks for reused datasets and research groups.
- Crossref/Crossmark and repository-update monitoring.
- Dependency invalidation when a source changes status.
- “Review required” warnings on affected conclusions.

## 0.6 — Tests and conclusions

- Falsifiable experiment records.
- Predeclared success and failure thresholds.
- Prototype, benchmark, survey, simulation, or security-test results.
- Conclusions that depend on exact evidence and test records.
- Current verdict, strongest objection, potentially novel remainder, and cheapest next test.

## 0.7 — Production foundation

- PostgreSQL and object storage migration.
- OAuth/multi-user workspaces.
- Encrypted backups and restore drills.
- Job queue, budgets, model router, tracing, and evaluation suite.
- Exportable provenance package.
- Production deployment separate from Codex Cloud.

## Expansion gate

Do not expand into a broad autonomous agent platform until benchmark use shows that structured case files materially outperform a conventional research report.
