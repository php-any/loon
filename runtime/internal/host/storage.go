package host

import (
	"context"
	"fmt"
	"github.com/php-any/loom/framework"
	"io/fs"
	"os"
	"strings"
)

// SQL 迁移由应用维护，运行时读取，不需要重新编译宿主。
var migrationFiles = os.DirFS("database")

func studioMigrations() ([]loom.Migration, error) {
	entries, err := os.ReadDir("database/migrations")
	if err != nil {
		return nil, err
	}
	plan := []loom.Migration{}
	for _, e := range entries {
		if !strings.HasSuffix(e.Name(), ".up.sql") {
			continue
		}
		version := strings.TrimSuffix(e.Name(), ".up.sql")
		up, err := fs.ReadFile(migrationFiles, "migrations/"+e.Name())
		if err != nil {
			return nil, err
		}
		down, err := fs.ReadFile(migrationFiles, "migrations/"+version+".down.sql")
		if err != nil {
			return nil, fmt.Errorf("迁移 %s 缺少回滚文件：%w", version, err)
		}
		plan = append(plan, loom.Migration{Version: version, Up: string(up), Down: string(down)})
	}
	return plan, nil
}

func openStudio(path string) (*loom.Services, error) {
	s, err := loom.OpenServices(path)
	if err != nil {
		return nil, err
	}
	plan, err := studioMigrations()
	if err == nil {
		err = s.Migrate(context.Background(), "studio", plan, false)
	}
	if err != nil {
		s.Close()
		return nil, err
	}
	return s, nil
}
