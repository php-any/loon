package loom

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"time"
)

// Tick materializes due schedules and claims one durable job. Delivery is at least
// once: application jobs must make side effects idempotent using their job ID.
func (s *Services) Tick(ctx context.Context, run func(context.Context, string) (string, error)) error {
	now := time.Now().Unix()
	if err := s.materializeSchedules(ctx, now); err != nil {
		return err
	}
	lease := randomToken()
	var id, owner int64
	var kind, payload string
	var attempt int
	var err error
	// The atomic UPDATE ... RETURNING is the claim, including recovery after a crash.
	err = s.DB.QueryRowContext(ctx, `UPDATE loom_jobs SET state='running',attempts=attempts+1,lease=?,lease_until=? WHERE id=(SELECT id FROM loom_jobs WHERE (state='pending' AND available<=?) OR (state='running' AND lease_until<=? AND attempts<3) ORDER BY id LIMIT 1) RETURNING id,kind,payload,owner,attempts`, lease, now+60, now, now).Scan(&id, &kind, &payload, &owner, &attempt)
	if errors.Is(err, sql.ErrNoRows) {
		_, err = s.DB.ExecContext(ctx, `UPDATE loom_jobs SET state='failed',error='Worker lease expired after final attempt' WHERE state='running' AND lease_until<=? AND attempts>=3`, now)
		return err
	}
	if err != nil {
		return err
	}
	envelope, err := json.Marshal(map[string]any{"id": id, "kind": kind, "payload": json.RawMessage(payload), "owner": owner, "attempt": attempt})
	if err != nil {
		return err
	}
	jobCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	result, jobErr := run(jobCtx, string(envelope))
	cancel()
	// Finish after cooperative cancellation using a bounded independent context.
	finish, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if jobErr == nil {
		_, err = s.DB.ExecContext(finish, `UPDATE loom_jobs SET state='completed',result=?,error=NULL,lease=NULL WHERE id=? AND lease=?`, result, id, lease)
	} else {
		state := "pending"
		if attempt >= 3 {
			state = "failed"
		}
		_, err = s.DB.ExecContext(finish, `UPDATE loom_jobs SET state=?,available=?,error=?,lease=NULL WHERE id=? AND lease=?`, state, time.Now().Unix()+int64(attempt*attempt*2), fmt.Sprintf("%v", jobErr), id, lease)
	}
	return err
}

func (s *Services) materializeSchedules(ctx context.Context, now int64) error {
	conn, err := s.DB.Conn(ctx)
	if err != nil {
		return err
	}
	defer conn.Close()
	if _, err = conn.ExecContext(ctx, "BEGIN IMMEDIATE"); err != nil {
		return err
	}
	defer conn.ExecContext(context.Background(), "ROLLBACK")
	rows, err := conn.QueryContext(ctx, `SELECT name,kind,payload,owner,interval_seconds,expression,timezone FROM loom_schedules WHERE next_run<=? ORDER BY name LIMIT 100`, now)
	if err != nil {
		return err
	}
	type due struct {
		name, kind, payload string
		owner, interval     int64
		expr, zone          sql.NullString
	}
	list := []due{}
	for rows.Next() {
		var d due
		if err = rows.Scan(&d.name, &d.kind, &d.payload, &d.owner, &d.interval, &d.expr, &d.zone); err != nil {
			rows.Close()
			return err
		}
		list = append(list, d)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return err
	}
	for _, d := range list {
		next := now + d.interval
		if d.expr.Valid {
			cron, e := ParseCron(d.expr.String, d.zone.String)
			if e != nil {
				return e
			}
			t, e := cron.Next(time.Unix(now, 0))
			if e != nil {
				return e
			}
			next = t.Unix()
		}
		if _, err = conn.ExecContext(ctx, `INSERT INTO loom_jobs(kind,payload,owner,available,created) VALUES(?,?,?,?,?)`, d.kind, d.payload, d.owner, now, now); err != nil {
			return err
		}
		if _, err = conn.ExecContext(ctx, "UPDATE loom_schedules SET next_run=? WHERE name=?", next, d.name); err != nil {
			return err
		}
	}
	_, err = conn.ExecContext(ctx, "COMMIT")
	return err
}
func (s *Services) Work(ctx context.Context, run func(context.Context, string) (string, error), onError func(error)) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if err := s.Tick(ctx, run); err != nil && ctx.Err() == nil {
				onError(err)
			}
		}
	}
}
