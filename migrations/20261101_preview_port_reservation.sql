-- Application preview ports are reserved per host, not globally.
-- This is metadata only: a reservation does not start a listener or open a firewall.
-- No foreign keys, so staged project registration and reassignment stay independent.
CREATE TABLE IF NOT EXISTS dm_preview_port_reservation (
    project_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    machine VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    UNIQUE KEY uq_dm_preview_port_machine_port (machine, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
