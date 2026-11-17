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
