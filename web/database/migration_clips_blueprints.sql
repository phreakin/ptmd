CREATE TABLE IF NOT EXISTS clip_blueprints
(
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id           INT UNSIGNED                                           NULL,
    platform          VARCHAR(80)                                            NOT NULL,
    topic             VARCHAR(120)                                           NULL,
    title             VARCHAR(255)                                           NULL,
    hook_asset_id     INT UNSIGNED                                           NULL,
    setup_asset_id    INT UNSIGNED                                           NULL,
    payoff_asset_id   INT UNSIGNED                                           NULL,
    loop_asset_id     INT UNSIGNED                                           NULL,
    overlay_asset_id  INT UNSIGNED                                           NULL,
    subtitle_asset_id INT UNSIGNED                                           NULL,
    caption_asset_id  INT UNSIGNED                                           NULL,
    source_video_path VARCHAR(255)                                           NULL,
    output_video_path VARCHAR(255)                                           NULL,
    blueprint_json    JSON                                                   NULL,
    status            ENUM ('draft','ready','rendering','rendered','failed') NOT NULL DEFAULT 'draft',
    created_at        DATETIME                                               NOT NULL,
    updated_at        DATETIME                                               NOT NULL,

    CONSTRAINT fk_cb_case FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_hook FOREIGN KEY (hook_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_setup FOREIGN KEY (setup_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_payoff FOREIGN KEY (payoff_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_loop FOREIGN KEY (loop_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_overlay FOREIGN KEY (overlay_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_subtitle FOREIGN KEY (subtitle_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_caption FOREIGN KEY (caption_asset_id) REFERENCES assets (id) ON DELETE SET NULL,

    INDEX idx_cb_case_platform (case_id, platform),
    INDEX idx_cb_status (status)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;