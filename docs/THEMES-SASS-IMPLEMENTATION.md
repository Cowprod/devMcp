# devMCP — Theme registry and local Sass (implementation contract)

Status: design baseline, not deployed. Development branch: main.

## Scope
- A theme is a first-class devMCP project, backed by its own Git repository and workspace.
- Projects select a versioned theme at creation or later; local project overrides survive theme upgrades.
- Sass compiles on the workspace host, never through GitHub Actions.
- Cordova icon defaults are defined by the theme, but overridden per application.
- Initial identities: Quartz (personal), Listee (✅), Builder (👷); Clio assets TBD. Existing listee-bootstrap must be reused rather than recreated.

## Theme manifest (theme.json)
```json
{
  "schema_version": 1,
  "id": "listee",
  "name": "Listee",
  "framework": "bootstrap5",
  "sass": {
    "entry": "scss/theme.scss",
    "output": "dist/bootstrap.min.css",
    "source_map": false
  },
  "preview": "preview/index.html",
  "assets": {"logo": "assets/logo.svg"},
  "cordova": {"icon": {"kind": "emoji", "value": "✅", "background": "#ffffff"}}
}
```

## Execution and security
- Add an explicit `sass.build` project action with fixed Dart Sass executable path, fixed allowed manifest-relative input/output, no arbitrary shell, and bounded execution.
- Install/pin Dart Sass per host and expose compiler version/capability. Do not assume it is installed.
- Validate all paths against workspace root, reject symlink escapes, enforce SCSS input and CSS output extensions.
- Capture stderr/stdout in devMCP jobs. Atomic output replacement; retain last good CSS on failure.
- Optional watch mode is a supervised per-project service with start/stop/status, never a long-running synchronous MCP action.
- Source files are editable independently of publishing. No commit per Sass compilation.
- Compiled CSS can be committed on explicit release; consumers install immutable tags/SHAs, not a moving main branch.

## Theme registry
- Registry fields: id, repository, project_id, version/tag/SHA, manifest digest, status.
- Project fields: theme_id, theme_revision, local overrides, icon overrides.
- Add catalog/list, preview, install, update-preview, update-apply; no silent upgrade.
- On install, vendor the resolved assets in the consumer project; avoid runtime network/CDN dependency.
- A theme update must preview a diff and leave application overrides intact.

## Cordova
- Render emoji once to deterministic raster master, not OS-dependent runtime glyphs.
- Accept uploaded PNG/SVG assets too; validate SVG and image inputs.
- Generate iOS and Android icon resources, including adaptive Android foreground/background, with platform-specific safe zones.
- Do not overwrite user-supplied icon assets without explicit confirmation.

## Delivery sequence
1. Discover current project action registration, host package/runtime, and theme-listee files.
2. Implement and test fixed-path Sass build on one host and project; verify output and failure handling.
3. Add first-class theme project metadata and registry; migrate existing listee-bootstrap without losing history.
4. Add theme selector to project creation and editable settings; pin version and preserve overrides.
5. Add Cordova icon builder and build integration; test iOS/Android outputs.

## Acceptance
- Change a Listee SCSS variable, compile locally, preview without GitHub commit or Actions run.
- A syntax error produces a job error and does not replace last working CSS.
- Release theme v1, install it into a new project, then preview v2 without changing the project.
- Create a Cordova app with Listee and see the default ✅ icon; override it without modifying the theme.
