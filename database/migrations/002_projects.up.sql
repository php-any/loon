CREATE TABLE studio_projects(id BIGINT PRIMARY KEY AUTO_INCREMENT, user_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, created BIGINT NOT NULL DEFAULT (UNIX_TIMESTAMP()), UNIQUE(user_id,name),FOREIGN KEY (user_id) REFERENCES loom_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
ALTER TABLE studio_tasks ADD COLUMN project_id BIGINT;
ALTER TABLE studio_tasks ADD FOREIGN KEY(project_id) REFERENCES studio_projects(id);
CREATE INDEX studio_tasks_project ON studio_tasks(user_id,project_id);
