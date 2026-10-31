package loom

import (
	"context"
	"database/sql"
	"fmt"
)

// Runs against an ephemeral database, never against the application's connection.
func migrationDemo(ctx context.Context) (any, error) {
	db, err := sql.Open("sqlite", ":memory:")
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	defer db.Close()
	s := &Services{DB: db}
	plan := []Migration{{Version: "001_notebooks", Up: "CREATE TABLE notebooks(id INTEGER PRIMARY KEY,title TEXT NOT NULL); INSERT INTO notebooks(title) VALUES('保留下来的想法');", Down: "DROP TABLE notebooks;"}, {Version: "002_color", Up: "ALTER TABLE notebooks ADD COLUMN color TEXT NOT NULL DEFAULT 'sage';", Down: "ALTER TABLE notebooks DROP COLUMN color;"}}
	events := []string{}
	if err = s.Migrate(ctx, "demo", plan[:1], false); err != nil {
		return nil, err
	}
	events = append(events, "001 已提交：创建 notebooks，写入 1 条数据")
	if err = s.Migrate(ctx, "demo", plan, false); err != nil {
		return nil, err
	}
	events = append(events, "002 已提交：增加 color 列，原数据保留")
	if err = s.Migrate(ctx, "demo", plan, true); err != nil {
		return nil, err
	}
	events = append(events, "回滚最后一批：撤销 002，保留 001 和原数据")
	broken := append([]Migration{}, plan[:1]...)
	broken = append(broken, Migration{Version: "002_broken", Up: "ALTER TABLE notebooks ADD COLUMN temporary TEXT; INSERT INTO missing_table VALUES(1);", Down: "SELECT 1;"})
	if err = s.Migrate(ctx, "demo", broken, false); err == nil {
		return nil, fmt.Errorf("demo expected a rollback")
	}
	var columns int
	if err = db.QueryRowContext(ctx, "SELECT COUNT(*) FROM pragma_table_info('notebooks') WHERE name='temporary'").Scan(&columns); err != nil {
		return nil, err
	}
	if columns != 0 {
		return nil, fmt.Errorf("failed migration leaked DDL")
	}
	events = append(events, "故意执行错误 SQL：整批回滚，temporary 列和版本记录均未留下")
	if err = s.Migrate(ctx, "demo", plan, false); err != nil {
		return nil, err
	}
	if err = s.Migrate(ctx, "demo", plan, false); err != nil {
		return nil, err
	}
	events = append(events, "重新升级并重复执行：002 只应用一次")
	status, err := s.MigrationStatus(ctx, "demo", plan)
	if err != nil {
		return nil, err
	}
	var title, color string
	if err = db.QueryRowContext(ctx, "SELECT title,color FROM notebooks WHERE id=1").Scan(&title, &color); err != nil {
		return nil, err
	}
	return map[string]any{"events": events, "versions": status, "record": map[string]string{"title": title, "color": color}, "isolated": true}, nil
}
