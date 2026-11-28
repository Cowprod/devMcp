SELECT dm_project_provisioning.project_id, dm_project_provisioning.state, dm_project_provisioning.machine,
       dm_project_provisioning.workspace_path, dm_project_provisioning.actions_enabled, dm_preview_port_reservation.port,
       RTY_CH_CODE AS runtime_type, PRT_CH_ENTRYPOINT AS entrypoint, PRT_CH_DOCUMENTROOT AS document_root,
       PRT_CH_HEALTHPATH AS health_path, PRT_CH_DESIREDSTATE AS desired_state, PRT_N_REVISION AS revision,
       RTY_CH_SUPERVISOR AS supervisor, RTY_CH_PROTOCOL AS protocol, RTY_BL_SELECTABLE AS selectable
FROM dm_project_provisioning
JOIN dm_preview_port_reservation ON dm_preview_port_reservation.project_id = dm_project_provisioning.project_id
    AND dm_preview_port_reservation.machine = dm_project_provisioning.machine
LEFT JOIN T_PROJECTRUNTIME ON PRT_CH_ACTIVE = dm_project_provisioning.project_id
LEFT JOIN T_RUNTIMETYPE ON RTY_N_ID = PRT_N_RUNTIMETYPE AND RTY_DT_SUPPRESSION IS NULL
WHERE dm_project_provisioning.project_id = %%PRT_CH_PROJECT%% COLLATE utf8mb4_unicode_ci
