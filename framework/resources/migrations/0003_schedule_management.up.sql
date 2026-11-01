ALTER TABLE loom_schedules ADD COLUMN enabled BIGINT NOT NULL DEFAULT 1;
CREATE TABLE loom_schedule_runs(id BIGINT PRIMARY KEY AUTO_INCREMENT,schedule_name VARCHAR(255) NOT NULL,job_id BIGINT NOT NULL,created BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE INDEX loom_schedule_runs_name ON loom_schedule_runs(schedule_name,id);
