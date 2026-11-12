# GPU access for Linux lab projects

The managed project runtime defaults to `PrivateDevices=yes`. GPU workloads need
an explicit local device grant. From a trusted checkout on the target agent:

```sh
sudo php bin/project-gpu-access.php grant rtx-tts-node-probe
```

The helper discovers existing DRM render nodes, AMD KFD and NVIDIA device nodes,
then writes a project-specific systemd drop-in. It sets `PrivateDevices=no`,
keeps `DevicePolicy=closed`, allows the detected GPU nodes for read/write and
adds their non-root owning groups. Other service protections remain unchanged.
The grant exposes the host device namespace but the cgroup policy restricts
which physical devices can be opened. It does not grant root to the application.

The service restarts when its grant changes. Existing managed drop-ins are
backed up in `/var/backups/devmcp-project-gpu`; service restart failure restores
the previous drop-in. Drop-ins survive normal devMcp redeployments.
Re-run the grant when GPU device nodes change after driver/hardware changes.
An absent GPU fails explicitly; no CPU fallback is selected.

To revoke (also restarts the project):

```sh
sudo php bin/project-gpu-access.php revoke rtx-tts-node-probe
```

Validate device visibility and effective permissions from the application,
then run a real compute test before claiming CUDA/ROCm compatibility. The helper
does not install drivers/frameworks, test models or implement the future central
hardware capability registry. macOS requires a separate implementation.
