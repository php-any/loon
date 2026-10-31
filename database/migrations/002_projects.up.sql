CREATE TABLE studio_projects(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES loom_users(id), name TEXT NOT NULL, created INTEGER NOT NULL DEFAULT (unixepoch()), UNIQUE(user_id,name));
ALTER TABLE studio_tasks ADD COLUMN project_id INTEGER REFERENCES studio_projects(id);
CREATE INDEX studio_tasks_project ON studio_tasks(user_id,project_id);
