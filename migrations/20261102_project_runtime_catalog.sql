-- Project runtime choices only: never starts a process or opens a network port.
-- Port reservations remain exclusively in dm_preview_port_reservation.
CREATE TABLE IF NOT EXISTS dm_runtime_type (
    code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    protocol ENUM('http', 'tcp') NOT NULL DEFAULT 'http',
    supervisor VARCHAR(24) NOT NULL DEFAULT 'systemd',
    selectable TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_project_runtime (
    project_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    runtime_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    desired_state ENUM('stopped', 'running') NOT NULL DEFAULT 'stopped',
    entrypoint VARCHAR(255) NULL,
    document_root VARCHAR(255) NULL,
    health_path VARCHAR(255) NOT NULL DEFAULT '/',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dm_project_runtime_type FOREIGN KEY (runtime_type)
        REFERENCES dm_runtime_type (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed fixed backends without overriding local catalogue changes.
INSERT IGNORE INTO dm_runtime_type (code, label, protocol, supervisor, selectable) VALUES
    ('static', 'Statique HTTP', 'http', 'systemd', 1),
    ('php', 'PHP', 'http', 'systemd', 1),
    ('node', 'Node.js', 'http', 'systemd', 1),
    ('python', 'Python', 'http', 'systemd', 1);
