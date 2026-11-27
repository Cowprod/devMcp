# Agent instructions

## Cowprod shared development and CI policy

Applies to all work in this repository. Central policy: https://github.com/Cowprod/referenciel/blob/main/DEVELOPPEMENT_AGENTS_CI.md

- Read the entire requested mission and batch related changes into a coherent implementation pass.
- Prefer local iterative tests and fixes before pushing; avoid one push per small correction.
- Keep commits logically scoped and traceable; push once per coherent validated batch when practical.
- Preserve mandatory tests, security checks, and quality gates; do not skip checks merely to save minutes.
- Avoid redundant GitHub Actions runs on push and pull_request; prefer path filters and cancellation of superseded runs where safe.
- Pay particular attention to costly macOS/iOS workflows.
- Report what changed, which tests ran locally and in CI, and any exceptions.
- These rules do not override repository-specific safety or release instructions.

## Mandatory Cowprod coding standards

Before designing or modifying any code, SQL schema, migration, API or UI, every agent (including ChatGPT and Codex) MUST read the current applicable standards in the central repository:
- https://github.com/Cowprod/referenciel/blob/main/README.md
- https://github.com/Cowprod/referenciel/blob/main/NOMMAGE_SQL_PHP.md
- https://github.com/Cowprod/referenciel/blob/main/FRONTEND.md
- https://github.com/Cowprod/referenciel/blob/main/DEVELOPPEMENT_AGENTS_CI.md

These are normative, not optional examples. Apply the current SQL naming, PHP helpers, history and soft-delete rules, module structure, frontend components and AJAX conventions to new business code. Reuse the shared socle rather than inventing parallel helpers or frameworks. Do not silently substitute alternative conventions. If the applicable standards cannot be accessed, or conflict with existing code or security requirements, report the blocker before implementing the affected changes.

Existing nonconforming code is technical debt, not precedent for new code. Preserve compatibility and do not rename existing tables or refactor unrelated modules without an approved migration plan. Reuse existing registries and identifiers; never create a duplicate machine registry. Any exception (including append-only high-volume telemetry, retention and physical purge) must be explicitly documented and approved.

Before committing, review the changed files against the applicable Cowprod standards and report any remaining deviations. Group related changes and tests in accordance with the CI policy above.
