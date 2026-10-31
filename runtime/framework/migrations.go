package loom

import (
	"context"
	"database/sql"
	"fmt"
	"sort"
	"time"
)

// Migration SQL is trusted, version-controlled application code. Do not include
// transaction control statements; the runner owns the transaction.
type Migration struct {
	Version string
	Up      string
	Down    string
}
type MigrationStatus struct {
	Version   string `json:"version"`
	Applied   bool   `json:"applied"`
	Batch     int    `json:"batch"`
	AppliedAt int64  `json:"applied_at"`
}

func migrationSum(m Migration) string { return digest(m.Up + "\x00" + m.Down) }
func migrationPlan(plan []Migration) error {
	for i, m := range plan {
		if m.Version == "" || m.Up == "" || m.Down == "" {
			return fmt.Errorf("migration requires version, up and down")
		}
		if i > 0 && plan[i-1].Version >= m.Version {
			return fmt.Errorf("migration versions must be strictly ordered")
		}
	}
	return nil
}
func migrationLedger(ctx context.Context, db *sql.DB) error {
	_, err := db.ExecContext(ctx, `CREATE TABLE IF NOT EXISTS loom_migrations(namespace TEXT NOT NULL,version TEXT NOT NULL,checksum TEXT NOT NULL,batch INTEGER NOT NULL,applied_at INTEGER NOT NULL,PRIMARY KEY(namespace,version))`)
	return err
}

// Migrate atomically applies all pending migrations, or rolls back the last batch.
// BEGIN IMMEDIATE serializes runners across connections/processes before inspection.
func (s *Services) Migrate(ctx context.Context, namespace string, plan []Migration, down bool) error {
	if namespace == "" {
		return fmt.Errorf("migration namespace is required")
	}
	if err := migrationPlan(plan); err != nil {
		return err
	}
	if err := migrationLedger(ctx, s.DB); err != nil {
		return err
	}
	conn, err := s.DB.Conn(ctx)
	if err != nil {
		return err
	}
	defer conn.Close()
	if _, err = conn.ExecContext(ctx, "BEGIN IMMEDIATE"); err != nil {
		return err
	}
	defer conn.ExecContext(context.Background(), "ROLLBACK")
	rows, err := conn.QueryContext(ctx, "SELECT version,checksum,batch FROM loom_migrations WHERE namespace=? ORDER BY version", namespace)
	if err != nil {
		return err
	}
	applied := map[string]int{}
	batch := 0
	known := map[string]Migration{}
	for _, m := range plan {
		known[m.Version] = m
	}
	for rows.Next() {
		var version, sum string
		var b int
		if err = rows.Scan(&version, &sum, &b); err != nil {
			rows.Close()
			return err
		}
		m, ok := known[version]
		if !ok || migrationSum(m) != sum {
			rows.Close()
			return fmt.Errorf("migration drift: %s/%s", namespace, version)
		}
		applied[version] = b
		if b > batch {
			batch = b
		}
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return err
	}
	missing := false
	for _, m := range plan {
		if _, ok := applied[m.Version]; !ok {
			missing = true
		} else if missing {
			return fmt.Errorf("migration history is not a prefix of the plan")
		}
	}
	if down {
		for i := len(plan) - 1; i >= 0; i-- {
			m := plan[i]
			if b, ok := applied[m.Version]; ok && b == batch {
				if _, err = conn.ExecContext(ctx, m.Down); err != nil {
					return fmt.Errorf("rollback %s: %w", m.Version, err)
				}
				if _, err = conn.ExecContext(ctx, "DELETE FROM loom_migrations WHERE namespace=? AND version=?", namespace, m.Version); err != nil {
					return err
				}
			}
		}
	} else {
		for _, m := range plan {
			if _, ok := applied[m.Version]; ok {
				continue
			}
			if _, err = conn.ExecContext(ctx, m.Up); err != nil {
				return fmt.Errorf("migration %s: %w", m.Version, err)
			}
			if _, err = conn.ExecContext(ctx, "INSERT INTO loom_migrations VALUES(?,?,?,?,?)", namespace, m.Version, migrationSum(m), batch+1, time.Now().Unix()); err != nil {
				return err
			}
		}
	}
	_, err = conn.ExecContext(ctx, "COMMIT")
	return err
}
func (s *Services) MigrationStatus(ctx context.Context, namespace string, plan []Migration) ([]MigrationStatus, error) {
	if err := migrationPlan(plan); err != nil {
		return nil, err
	}
	if err := migrationLedger(ctx, s.DB); err != nil {
		return nil, err
	}
	rows, err := s.DB.QueryContext(ctx, "SELECT version,batch,applied_at,checksum FROM loom_migrations WHERE namespace=?", namespace)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	seen := map[string]MigrationStatus{}
	known := map[string]Migration{}
	for _, m := range plan {
		known[m.Version] = m
	}
	for rows.Next() {
		var st MigrationStatus
		var sum string
		st.Applied = true
		if err = rows.Scan(&st.Version, &st.Batch, &st.AppliedAt, &sum); err != nil {
			return nil, err
		}
		m, ok := known[st.Version]
		if !ok || migrationSum(m) != sum {
			return nil, fmt.Errorf("migration drift: %s", st.Version)
		}
		seen[st.Version] = st
	}
	if err = rows.Err(); err != nil {
		return nil, err
	}
	result := []MigrationStatus{}
	for _, m := range plan {
		st, ok := seen[m.Version]
		if !ok {
			st.Version = m.Version
		}
		result = append(result, st)
	}
	sort.Slice(result, func(i, j int) bool { return result[i].Version < result[j].Version })
	return result, nil
}
