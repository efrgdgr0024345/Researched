# Product definition

## One-sentence definition

Researched is a mobile-first idea laboratory that preserves original thinking, verifies what the system believes the user means, finds existing work, stores exact sources, argues against unsupported assumptions, and converts unresolved questions into tests.

## The job to be done

A user has an incomplete idea. They need more than encouragement or a polished answer. They need to know:

- whether the system understood the idea;
- which claims and assumptions carry the idea;
- what papers, repositories, standards, and failed projects already exist;
- which sources deserve trust and within what scope;
- what the strongest objection is;
- which part may actually be new;
- what inexpensive result would strengthen, revise, or kill the idea.

## Central product object

The central object is an **idea case file**, not a conversation.

A mature case file contains:

1. original wording and attachments;
2. accepted interpretation history;
3. claims and assumptions;
4. investigation protocol and search coverage;
5. versioned papers, repositories, standards, and datasets;
6. claim-to-evidence relationships;
7. structured challenges and responses;
8. falsification tests and results;
9. evidence-dependent conclusions;
10. a complete provenance and decision log.

## Source-of-truth model

The phrase “source of truth” is separated into three layers:

1. **Source of record** — the exact paper, repository commit, dataset, or specification examined.
2. **Truth about the source's claim** — what that source says, linked to exact passages, tables, figures, code, or results.
3. **Current best-supported conclusion** — what the body of evidence supports after scope, independence, contradiction, replication, correction, and retraction are considered.

A trusted paper is never universal truth. It is trusted evidence for one or more bounded claims.

## Required critical behaviour

The system must steelman the idea before attacking it. It then checks:

- meaning and ambiguity;
- prior work and terminology mismatch;
- causal/mechanism gaps;
- evidence quality and independence;
- feasibility and real-world constraints;
- user value;
- adversarial or abuse cases;
- simpler alternatives;
- explicit falsification conditions.

Every objection is labelled as evidence-backed, logical but unverified, speculative, or resolved.

## Initial domain boundary

The first release focuses on technical and software ideas. These have accessible papers, public repositories, standards, repeatable tests, and lower consequence than clinical or legal decision support.

## Product success

The product succeeds when a case file causes the user to do at least one of the following for a good reason:

- correct a misunderstood idea;
- discover important prior work;
- remove or revise a weak assumption;
- identify a load-bearing risk;
- isolate the potentially novel remainder;
- abandon a weak project before overspending;
- run a more decisive experiment;
- preserve an evidence trail that remains useful after the chat is forgotten.
