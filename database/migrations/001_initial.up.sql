CREATE TABLE IF NOT EXISTS studio_tasks(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES loom_users(id), title TEXT NOT NULL, completed INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL DEFAULT (unixepoch()));
CREATE INDEX IF NOT EXISTS studio_tasks_owner ON studio_tasks(user_id,id);
CREATE TABLE IF NOT EXISTS studio_reports(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES loom_users(id), job_id INTEGER NOT NULL UNIQUE, total INTEGER NOT NULL, completed INTEGER NOT NULL, created INTEGER NOT NULL DEFAULT (unixepoch()));
