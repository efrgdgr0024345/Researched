# First Codex Cloud task

Paste the prompt below into a Codex Cloud task after the bootstrap pull request is merged.

---

Read `AGENTS.md`, `README.md`, `docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, and `docs/ROADMAP.md`. Run `bash scripts/check.sh` before making changes.

Implement milestone **0.2: claim-to-evidence records** as one end-to-end mobile feature. Do not introduce a framework, database service, Composer dependency, or destructive migration.

Required behaviour:

1. Add append-only evidence records to the JSON schema. An evidence record must include: idea ID, source ID, optional claim ID, evidence type (`quotation`, `source_claim`, `measurement`, `code_result`, `user_note`), exact text, location label (such as page/section/table/code path), scope/limitations, review status, creator (`user` or `ai`), created time, and stable record hash.
2. Add server-side validation and audit events. A source and claim must belong to the same idea.
3. Add a mobile Sources action named **Add evidence**. The user must be able to choose a claim, enter exact text and location, explain scope, and save it.
4. Show evidence under both its source and linked claim. Never render user/source text as raw HTML.
5. Add an Evidence count to the case header.
6. Add behaviour tests for creation, cross-idea rejection, source linkage, claim linkage, append-only storage, and audit records.
7. Update the README/API documentation and schema version with an explicit backward-compatible migration from existing version-1 JSON files.
8. Run `bash scripts/check.sh` and manually inspect the interface at 320 CSS pixels.

Non-negotiable acceptance rules:

- Do not automatically create evidence from a search result or paper title.
- Do not mark a source or challenge evidence-backed merely because an evidence record exists; human review status must remain visible.
- Do not modify original idea text, earlier versions, existing source hashes, or existing audit records.
- Do not commit runtime state, PDFs, `.env`, or secrets.

Open a pull request with a concise architecture note, test output, mobile screenshot, and all remaining limitations.

---
