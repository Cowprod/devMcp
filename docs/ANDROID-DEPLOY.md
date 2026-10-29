# Android APK deployment (generic devMcp action)

The reusable runner is `bin/android-install.php`. It uses a fixed APK under the project workspace, calls ADB without a shell, and preserves app data. It does **not** uninstall an existing app after a signature mismatch.

The administrator must configure a **per-project allowlist** of device serials using an `enum` parameter. Do not use the less restrictive `android_serial` parameter for this action.

Example for `/etc/devmcp/global.php`, inside the `multicam` project's `actions`:

```php
'android.install' => [
    'description' => 'Install the debug APK on an authorized MultiCam tablet.',
    'argv' => [
        '/usr/bin/php',
        '/opt/devmcp/bin/android-install.php',
        '/srv/devmcp-workspaces/multicam',
        'app/platforms/android/app/build/outputs/apk/debug/app-debug.apk',
        ['param' => 'device'],
    ],
    'parameters' => [
        'device' => [
            'type' => 'enum',
            'values' => [
                '61cc29567d91',
                '61d54bba7d91',
                '62614ac67d91',
                'c0d8514d7d87',
            ],
            'description' => 'Authorized Android device',
        ],
    ],
    'cwd' => '.',
    'timeout' => 180,
],
```

The worker and tunnel services must reload their configuration after this entry is added. Run one `android.install` action per device; for four devices, enqueue them sequentially.

## Validation

1. Verify PHP syntax of the runner and config.
2. Check that the action appears in `action_list` and that the `device` parameter is an enum.
3. Verify `android.devices` reports the target as `device` (not `unauthorized`).
4. Run the action for one device and inspect its result.
5. Install on the remaining devices sequentially.

## Safety

An existing incompatible signature causes a failure, not an automatic uninstall. Do not wipe recordings or application data. The runner validates that the resolved APK remains inside the configured project root. Its path is fixed by the administrator, not supplied by the model.
