-- Forward-only expansion of the dedicated hosting job action allowlist.
-- 006 may already be applied on an operator-managed central MySQL instance.
-- Re-running this ALTER is safe and does not enqueue, authorize or execute.
-- Explicit root/DB-admin migration approval remains required.
ALTER TABLE dm_remote_hosting_job
  MODIFY COLUMN action_name
  ENUM('prepare-all','activate-initial','abort-initial','commit-initial') NOT NULL;
