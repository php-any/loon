CREATE TABLE loom_monitoring_sessions(
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    correlation_id VARCHAR(128) NULL,
    channel VARCHAR(32) NOT NULL,
    tags JSON NOT NULL,
    duration_ms DOUBLE NOT NULL,
    created_at BIGINT NOT NULL,
    INDEX idx_loom_monitoring_sessions_correlation (correlation_id),
    INDEX idx_loom_monitoring_sessions_created (created_at),
    INDEX idx_loom_monitoring_sessions_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE loom_monitoring_entries(
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT NOT NULL,
    event VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    INDEX idx_loom_monitoring_entries_session (session_id),
    INDEX idx_loom_monitoring_entries_event (event),
    CONSTRAINT fk_loom_monitoring_entries_session FOREIGN KEY (session_id) REFERENCES loom_monitoring_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE loom_monitoring_session_tags(
    session_id BIGINT NOT NULL,
    tag_key VARCHAR(64) NOT NULL,
    tag_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (session_id, tag_key, tag_value),
    INDEX idx_loom_monitoring_session_tags_lookup (tag_key, tag_value),
    CONSTRAINT fk_loom_monitoring_session_tags_session FOREIGN KEY (session_id) REFERENCES loom_monitoring_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
