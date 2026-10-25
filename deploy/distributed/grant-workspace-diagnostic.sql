-- Apply after migration 004 as database administrator.
GRANT SELECT (id, machine_id, workspace_path)
ON devmcp_distributed.dm_deployment TO 'devmcp_writer'@'localhost';
GRANT SELECT (id, machine_id)
ON devmcp_distributed.dm_deployment TO 'devmcp_writer'@'localhost';
GRANT INSERT (deployment_id, machine_id, status, branch, commit_sha, inspected_at),
      UPDATE (deployment_id, machine_id, status, branch, commit_sha, inspected_at)
ON devmcp_distributed.dm_workspace_diagnostic TO 'devmcp_writer'@'localhost';
-- Read grants are assigned by the installer to the configured reader account.
-- Do not assume that a devmcp_reader SQL account exists.
