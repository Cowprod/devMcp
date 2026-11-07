<?php

declare(strict_types=1);

// Deliberately self-contained: no dependencies, configuration, database,
// shell execution, filesystem writes or access to caseRouter internals.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/healthz') {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true,"runtime":"php"}';
    exit;
}

if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>devMcp — démonstrateur PHP</title>
  <style>
    :root { color-scheme: dark; font-family: system-ui, sans-serif; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #18141f; color: #f7f2ff; }
    main { max-width: 38rem; margin: 1.5rem; padding: 2rem; border: 1px solid #73628a; border-radius: 1rem; }
    h1 { margin-top: 0; }
  </style>
</head>
<body>
  <main>
    <h1>devMcp — hébergement PHP</h1>
    <p>Application de démonstration sans dépendances, données privées ni écritures sur disque.</p>
    <p>État de santé local : <code>/healthz</code>.</p>
  </main>
</body>
</html>
