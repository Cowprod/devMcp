# Sass local in devMCP

The shared actions `sass.version` and `sass.build` use a fixed executable at `/opt/devmcp-tools/dart-sass/sass` on the **workspace host**. They do not invoke GitHub Actions. The host administrator must install a pinned Dart Sass release at that location; an absent compiler is reported as an error, not silently downloaded.

Each opted-in project contains a `sass.json` at its workspace root:

```json
{
  "schema_version": 1,
  "input": "scss/theme.scss",
  "output": "dist/bootstrap.min.css"
}
```

The source and output directories must exist. Paths are relative and bounded to the workspace, and the compiled file is atomically replaced only on success. The previous CSS remains untouched after Sass compilation errors.

These actions must be explicitly enabled for projects according to the existing devMCP project-action policy. On distributed agents, the same helper and pinned Dart Sass executable must be deployed locally before the action can succeed.

Current implementation: one-shot build and version reporting. Watch mode, automatic tool installation, theme registry and Cordova icon generation remain future work. This is intentionally a first increment.
