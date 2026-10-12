# Codex Cloud setup

This repository is prepared so Codex can inspect, edit, run, and verify it in an isolated cloud workspace.

## 1. Connect the repository

In Codex Cloud, create an environment and select:

```text
efrgdgr0024345/Researched
```

Use the repository default branch as the base. Codex should work on a fresh task branch and open a pull request rather than writing directly to `main`.

## 2. Environment setup command

```bash
bash scripts/setup.sh
```

The command is idempotent. It verifies PHP, creates ignored runtime directories, creates a local `.env` when absent, and runs the repository checks.

## 3. Safe environment variables

These are configuration rather than secrets:

```text
DATA_FILE=data/state.json
OPENAI_MODEL_CHEAP=gpt-6-luna
OPENAI_MODEL_STANDARD=gpt-6.1-sol
OPENALEX_MAILTO=<contact email, optional>
CROSSREF_MAILTO=<contact email, optional>
```

For a shared Codex environment, do not place a real workspace password in the repository. `APP_PASSWORD` can remain blank during isolated development.

## 4. Secrets

Configure only when the relevant task needs live integration:

```text
OPENAI_API_KEY
GITHUB_TOKEN
```

Use a network secret or secret environment value rather than a repository file. Scope `GITHUB_TOKEN` as narrowly as possible. The application must continue to pass tests without either secret.

## 5. Network allowlist

For live integration tests, allow HTTPS only to the exact services used:

```text
api.openai.com
api.openalex.org
api.crossref.org
api.github.com
```

Package-manager access is not required by this version. Leave network disabled for tasks that do not need live APIs.

## 6. Standard commands

```bash
# Required before and after every task
bash scripts/check.sh

# Start an interactive preview
bash scripts/start.sh

# Alternative port
PORT=8090 bash scripts/start.sh
```

## 7. Codex task pattern

Each task should:

1. Read `AGENTS.md` and the linked issue.
2. Run the baseline checks.
3. Change one reviewable vertical behaviour.
4. Add a regression check.
5. Run the complete checks again.
6. Review the diff for secrets and runtime data.
7. Open a pull request describing limitations and mobile testing.

Do not ask Codex to “finish the whole platform.” Give it one acceptance-tested milestone at a time.

## 8. GitHub review

Enable Codex code review on the repository after the first pull request is merged. `AGENTS.md` contains a `Code review rules` section so automated review focuses on provenance, source trust, immutability, security, and the no-key path. CI remains the hard executable gate.

## 9. Hosting boundary

Codex Cloud is a development workspace. It can run the application during a task, but production hosting, persistent user files, backups, and public authentication must live on a deployment target such as a managed PHP host or a container service.
