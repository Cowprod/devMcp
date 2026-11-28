SELECT dm_project_provisioning.project_id
FROM dm_project_provisioning
JOIN dm_preview_port_reservation ON dm_preview_port_reservation.project_id = dm_project_provisioning.project_id
    AND dm_preview_port_reservation.machine = dm_project_provisioning.machine
WHERE dm_project_provisioning.project_id = %%PRT_CH_PROJECT%% COLLATE utf8mb4_unicode_ci
    AND dm_project_provisioning.state = 'active' AND dm_preview_port_reservation.port BETWEEN 1024 AND 65535
FOR UPDATE
