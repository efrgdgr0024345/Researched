# Researched

**A mobile-first idea investigation and evidence workbench.**

Researched preserves an idea in the user's own words, creates a separate interpretation for approval, searches for papers and public GitHub projects, saves exact sources, records source trust explicitly, and pushes back with structured objections and falsification tests.

The chatbot or model is not the source of truth. The durable product is the **idea case file**.

## What works in the first vertical slice

- Immutable original idea record with SHA-256-derived record hash.
- Versioned AI-generated or manually corrected interpretations.
- Explicit user acceptance of an interpretation.
- Testable claim extraction.
- Live prior-work search through OpenAlex, Crossref, and GitHub.
- Candidate-source saving without automatic trust promotion.
- Exact PDF upload, private storage, size/type validation, and SHA-256 hashing.
- Source states: candidate, trusted, contested, superseded, retracted, excluded.
- Structured adversarial challenges with severity, evidence status, impact, and cheapest resolution test.
- Complete case activity history.
- Mobile-first interface that works without JavaScript frameworks.
- Deterministic fallback interpretation and criticism when no OpenAI key is configured.

## Deliberate limitations of version 0.1

This is a working foundation, not a completed research platform. It does **not yet**:

- extract page-level passages from uploaded PDFs;
- connect claims to exact source passages;
- monitor Crossmark or publishers for corrections/retractions;
- clone and pin exact GitHub commits for source-level inspection;
- calculate research coverage or systematic-review flow statistics;
- run experiments or maintain conclusion dependencies.

Those are staged in `docs/ROADMAP.md` rather than being faked in the UI.

## Requirements

- PHP 8.1 or newer.
- A browser.
- Internet access only for live paper/repository search and OpenAI analysis.
- No Composer, Node, SQL database, or API key is required for the base application.

## Run locally

```bash
git clone https://github.com/efrgdgr0024345/Researched.git
cd Researched
bash scripts/setup.sh
bash scripts/start.sh
```

Open `http://localhost:8080`.

The setup script copies `.env.example` to `.env`, creates runtime directories, and runs all checks.

## Configuration

Edit `.env`:

```dotenv
APP_PASSWORD=
DATA_FILE=data/state.json

OPENAI_API_KEY=
OPENAI_MODEL_CHEAP=gpt-6-luna
OPENAI_MODEL_STANDARD=gpt-6.1-sol

OPENALEX_MAILTO=
CROSSREF_MAILTO=
GITHUB_TOKEN=
```

`OPENAI_API_KEY` is optional. Without it, the app uses deterministic fallback behaviour and clearly labels that mode. `GITHUB_TOKEN` is optional but can increase public API limits; use the narrowest practical scope.

Never commit `.env`, runtime state, uploaded papers, or user material.

## Verify the repository

```bash
bash scripts/check.sh
```

This runs PHP syntax checks, a JavaScript syntax check when Node is available, and behaviour tests covering idea immutability, explicit interpretation acceptance, source promotion, structured pushback, and the audit trail.

## Codex Cloud

The repository is designed for Codex Cloud:

- `AGENTS.md` gives every agent the product and engineering rules.
- `scripts/setup.sh` creates a repeatable environment.
- `scripts/check.sh` is the required completion gate.
- GitHub Actions runs the same checks on PHP 8.1, 8.3, and 8.4.
- Small issue-to-branch-to-PR tasks protect the working vertical slice.

Use the exact environment configuration in `docs/CODEX_CLOUD.md` and begin with `docs/FIRST_CODEX_TASK.md`.

Codex Cloud is the remote coding, build, and test workspace. It is **not** the permanent public hosting target. Production can later run on a PHP host or container after authentication, backup, and storage decisions are completed.

## Repository layout

```text
public/                 Web entry point and mobile UI
src/                    Domain, storage, search, HTTP, and OpenAI services
tests/                  Dependency-free behaviour checks
scripts/                Setup, start, and verification commands
data/                   Ignored runtime state and uploaded source files
docs/                   Product, architecture, Codex, and roadmap records
.github/                 CI, pull request, and issue templates
AGENTS.md                Non-negotiable instructions for Codex and reviewers
```

## Security model

- Optional password protection for the current single-user MVP.
- Same-site, HTTP-only session cookie.
- CSRF protection for writes.
- Strict content security policy.
- HTTPS-only saved external URLs.
- Uploaded PDFs stored outside the public directory.
- PDF signature and size checks.
- Real-path validation before serving an uploaded source.
- Secrets loaded from environment variables and never inserted into repository files.

Before exposing the app broadly, place it behind HTTPS and a production-grade identity layer; the optional shared password is appropriate only for a private early prototype.

## Product rules

Read `AGENTS.md` before making changes. The most important rules are:

1. Never overwrite the original idea.
2. Never silently accept an AI interpretation.
3. Never turn a discovered paper into “truth” automatically.
4. Never invent evidence or global novelty.
5. Challenge the strongest version of the idea and identify what would prove it wrong.
6. Keep all consequential changes traceable.

## Current status

`0.1.0 — working vertical slice`
