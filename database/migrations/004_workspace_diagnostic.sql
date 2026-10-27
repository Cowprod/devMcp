-- Apply explicitly before enabling workspace diagnostic reporting.
-- Diagnostics are informational only and never authorize remote execution.
CREATE TABLE IF NOT EXISTS dm_workspace_diagnostic (
  deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('present','missing','invalid','not_git') NOT NULL,
  branch VARCHAR(200) NULL,
  commit_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  inspected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_workspace_diagnostic_deployment FOREIGN KEY (deployment_id) REFERENCES dm_deployment(id),
  CONSTRAINT fk_workspace_diagnostic_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
