package loom

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/runtime"
	"golang.org/x/crypto/bcrypt"
	_ "modernc.org/sqlite"
)

// Services owns process resources, never PHP objects. Close after HTTP and workers drain.
type Services struct{ DB *sql.DB }

func OpenServices(path string) (*Services, error) {
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	s := &Services{DB: db}
	_, err = db.Exec(`PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
 CREATE TABLE IF NOT EXISTS loom_users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS loom_sessions(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES loom_users(id), csrf TEXT NOT NULL, expires INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS loom_jobs(id INTEGER PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL, owner INTEGER NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available INTEGER NOT NULL, lease TEXT, lease_until INTEGER, result TEXT, error TEXT, created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS loom_jobs_ready ON loom_jobs(state,available);
 CREATE TABLE IF NOT EXISTS loom_schedules(name TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL, owner INTEGER NOT NULL, interval_seconds INTEGER NOT NULL, next_run INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS loom_auth_limits(key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, until_at INTEGER NOT NULL);`)
	if err != nil {
		db.Close()
		return nil, err
	}
	if err = s.Migrate(context.Background(), "loom", []Migration{{Version: "0001_cron", Up: "ALTER TABLE loom_schedules ADD COLUMN expression TEXT; ALTER TABLE loom_schedules ADD COLUMN timezone TEXT;", Down: "ALTER TABLE loom_schedules DROP COLUMN timezone; ALTER TABLE loom_schedules DROP COLUMN expression;"}}, false); err != nil {
		db.Close()
		return nil, err
	}
	return s, nil
}
func (s *Services) Close() error { return s.DB.Close() }
func randomToken() string {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}
func digest(v string) string { h := sha256.Sum256([]byte(v)); return hex.EncodeToString(h[:]) }

type serviceError struct {
	Status  int
	Message string
}

func (e *serviceError) Error() string      { return e.Message }
func bad(status int, message string) error { return &serviceError{status, message} }

type command struct {
	Op         string          `json:"op"`
	SQL        string          `json:"sql"`
	Args       []any           `json:"args"`
	Statements []command       `json:"statements"`
	Name       string          `json:"name"`
	Email      string          `json:"email"`
	Password   string          `json:"password"`
	Kind       string          `json:"kind"`
	Payload    json.RawMessage `json:"payload"`
	Owner      int64           `json:"owner"`
	Delay      int64           `json:"delay"`
	Interval   int64           `json:"interval"`
	Expression string          `json:"expression"`
	Timezone   string          `json:"timezone"`
}
type serviceFunction struct {
	services *Services
	resolve  func() (*Services, error)
}

func (*serviceFunction) GetName() string { return "Loom\\host_call" }
func (*serviceFunction) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("command", 0)}
}
func (*serviceFunction) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("command", 0, data.NewBaseType("string"))}
}
func (f *serviceFunction) Call(ctx data.Context) (data.GetValue, data.Control) {
	v, _ := ctx.GetIndexValue(0)
	vm, ok := ctx.GetVM().(*runtime.RequestVM)
	if !ok || vm.HTTPRequest() == nil {
		return nil, data.NewErrorThrow(nil, errors.New("Loom services are not configured"))
	}
	services := f.services
	if services == nil && f.resolve != nil {
		var err error
		services, err = f.resolve()
		if err != nil {
			return nil, data.NewErrorThrow(nil, err)
		}
	}
	if services == nil {
		return nil, data.NewErrorThrow(nil, errors.New("Loom services are not configured"))
	}
	var c command
	if err := json.Unmarshal([]byte(v.AsString()), &c); err != nil {
		return nil, data.NewErrorThrow(nil, err)
	}
	result, err := services.call(vm.HTTPRequest(), c)
	envelope := map[string]any{"value": result}
	if err != nil {
		var public *serviceError
		if errors.As(err, &public) {
			envelope = map[string]any{"status": public.Status, "message": public.Message}
		} else {
			return nil, data.NewErrorThrow(nil, err)
		}
	}
	raw, err := json.Marshal(envelope)
	if err != nil {
		return nil, data.NewErrorThrow(nil, err)
	}
	return data.NewStringValue(string(raw)), nil
}

type identity struct {
	ID    int64  `json:"id"`
	Name  string `json:"name"`
	Email string `json:"email"`
	CSRF  string `json:"csrf"`
}

func (s *Services) identity(r *http.Request) (*identity, error) {
	cookie, err := r.Cookie("loom_session")
	if err != nil {
		return nil, bad(401, "请先登录")
	}
	u := &identity{}
	err = s.DB.QueryRowContext(r.Context(), `SELECT u.id,u.name,u.email,s.csrf FROM loom_sessions s JOIN loom_users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?`, digest(cookie.Value), time.Now().Unix()).Scan(&u.ID, &u.Name, &u.Email, &u.CSRF)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, bad(401, "登录已过期，请重新登录")
	}
	if err != nil {
		return nil, err
	}
	return u, nil
}
func (s *Services) call(r *http.Request, c command) (any, error) {
	ctx := r.Context()
	switch c.Op {
	case "cron.preview", "schedule.cron":
		cron, err := ParseCron(c.Expression, c.Timezone)
		if err != nil {
			return nil, bad(422, err.Error())
		}
		next, err := cron.Next(time.Now())
		if err != nil {
			return nil, bad(422, err.Error())
		}
		if c.Op == "cron.preview" {
			out := []string{}
			for i := 0; i < 5; i++ {
				out = append(out, next.In(cron.location).Format(time.RFC3339))
				next, err = cron.Next(next)
				if err != nil {
					return nil, bad(422, err.Error())
				}
			}
			return out, nil
		}
		if c.Name == "" || c.Kind == "" {
			return nil, bad(422, "Invalid schedule")
		}
		if len(c.Payload) == 0 {
			c.Payload = json.RawMessage(`{}`)
		}
		_, err = s.DB.ExecContext(ctx, `INSERT INTO loom_schedules(name,kind,payload,owner,interval_seconds,next_run,expression,timezone) VALUES(?,?,?,?,0,?,?,?) ON CONFLICT(name) DO UPDATE SET kind=excluded.kind,payload=excluded.payload,owner=excluded.owner,interval_seconds=0,next_run=CASE WHEN loom_schedules.expression=excluded.expression AND loom_schedules.timezone=excluded.timezone THEN loom_schedules.next_run ELSE excluded.next_run END,expression=excluded.expression,timezone=excluded.timezone`, c.Name, c.Kind, string(c.Payload), c.Owner, next.Unix(), c.Expression, c.Timezone)
		return true, err
	case "migration.demo":
		return migrationDemo(ctx)
	case "db.query":
		rows, err := s.DB.QueryContext(ctx, c.SQL, c.Args...)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		cols, err := rows.Columns()
		if err != nil {
			return nil, err
		}
		out := []map[string]any{}
		for rows.Next() {
			vals := make([]any, len(cols))
			ptrs := make([]any, len(cols))
			for i := range vals {
				ptrs[i] = &vals[i]
			}
			if err = rows.Scan(ptrs...); err != nil {
				return nil, err
			}
			row := map[string]any{}
			for i, k := range cols {
				row[k] = vals[i]
			}
			out = append(out, row)
		}
		return out, rows.Err()
	case "db.execute":
		res, err := s.DB.ExecContext(ctx, c.SQL, c.Args...)
		if err != nil {
			return nil, err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return nil, err
		}
		n, err := res.RowsAffected()
		return map[string]any{"id": id, "affected": n}, err
	case "db.batch":
		tx, err := s.DB.BeginTx(ctx, nil)
		if err != nil {
			return nil, err
		}
		defer tx.Rollback()
		for _, stmt := range c.Statements {
			if _, err = tx.ExecContext(ctx, stmt.SQL, stmt.Args...); err != nil {
				return nil, err
			}
		}
		return true, tx.Commit()
	case "auth.current":
		return s.identity(r)
	case "auth.guard":
		u, err := s.identity(r)
		if err != nil {
			return nil, err
		}
		if r.Method != "GET" && r.Method != "HEAD" {
			if r.Header.Get("X-CSRF-Token") != u.CSRF {
				return nil, bad(403, "请求校验失败，请刷新页面")
			}
			if err = checkOrigin(r); err != nil {
				return nil, err
			}
		}
		return u, nil
	case "auth.logout":
		if _, err := s.call(r, command{Op: "auth.guard"}); err != nil {
			return nil, err
		}
		cookie, _ := r.Cookie("loom_session")
		_, err := s.DB.ExecContext(ctx, "DELETE FROM loom_sessions WHERE token=?", digest(cookie.Value))
		return map[string]any{"cookie": sessionCookie(r, "", -1)}, err
	case "auth.register", "auth.login":
		return s.authenticate(r, c)
	case "queue.dispatch":
		if c.Kind == "" || c.Delay < 0 || c.Delay > 86400*30 {
			return nil, bad(422, "Invalid job")
		}
		if len(c.Payload) == 0 {
			c.Payload = json.RawMessage(`{}`)
		}
		res, err := s.DB.ExecContext(ctx, `INSERT INTO loom_jobs(kind,payload,owner,available,created) VALUES(?,?,?,?,?)`, c.Kind, string(c.Payload), c.Owner, time.Now().Unix()+c.Delay, time.Now().Unix())
		if err != nil {
			return nil, err
		}
		return res.LastInsertId()
	case "schedule.every":
		if c.Interval < 10 || c.Interval > 86400*30 || c.Name == "" || c.Kind == "" {
			return nil, bad(422, "Invalid schedule")
		}
		if len(c.Payload) == 0 {
			c.Payload = json.RawMessage(`{}`)
		}
		_, err := s.DB.ExecContext(ctx, `INSERT INTO loom_schedules(name,kind,payload,owner,interval_seconds,next_run) VALUES(?,?,?,?,?,?) ON CONFLICT(name) DO UPDATE SET kind=excluded.kind,payload=excluded.payload,interval_seconds=excluded.interval_seconds,next_run=CASE WHEN loom_schedules.expression IS NOT NULL OR loom_schedules.interval_seconds<>excluded.interval_seconds THEN excluded.next_run ELSE loom_schedules.next_run END,expression=NULL,timezone=NULL`, c.Name, c.Kind, string(c.Payload), c.Owner, c.Interval, time.Now().Unix()+c.Interval)
		return true, err
	}
	return nil, fmt.Errorf("unknown Loom operation: %s", c.Op)
}
func checkOrigin(r *http.Request) error {
	origin := r.Header.Get("Origin")
	u, err := url.Parse(origin)
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	if err != nil || u.Host != r.Host || u.Scheme != scheme {
		return bad(403, "请求来源不匹配")
	}
	return nil
}
func sessionCookie(r *http.Request, token string, age int) string {
	return (&http.Cookie{Name: "loom_session", Value: token, Path: "/", HttpOnly: true, Secure: r.TLS != nil, SameSite: http.SameSiteLaxMode, MaxAge: age}).String()
}
func (s *Services) authenticate(r *http.Request, c command) (any, error) {
	if err := checkOrigin(r); err != nil {
		return nil, err
	}
	c.Email = strings.ToLower(strings.TrimSpace(c.Email))
	c.Name = strings.TrimSpace(c.Name)
	if len(c.Email) > 254 || !strings.Contains(c.Email, "@") || len(c.Password) < 10 || len(c.Password) > 72 {
		return nil, bad(422, "请输入有效邮箱和 10–72 字节密码")
	}
	ctx := r.Context()
	now := time.Now().Unix()
	key := digest(c.Email)
	// A persistent per-account budget bounds password hashing and survives restarts.
	var attempts int
	err := s.DB.QueryRowContext(ctx, `INSERT INTO loom_auth_limits(key,attempts,until_at) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN until_at<=? THEN 1 ELSE attempts+1 END, until_at=CASE WHEN until_at<=? THEN excluded.until_at ELSE until_at END RETURNING attempts`, key, now+60, now, now).Scan(&attempts)
	if err != nil {
		return nil, err
	}
	if attempts > 10 {
		return nil, bad(429, "尝试过于频繁，请稍后再试")
	}
	var id int64
	if c.Op == "auth.register" {
		if c.Name == "" || len(c.Name) > 80 {
			return nil, bad(422, "请输入姓名")
		}
		hash, err := bcrypt.GenerateFromPassword([]byte(c.Password), bcrypt.DefaultCost)
		if err != nil {
			return nil, err
		}
		res, err := s.DB.ExecContext(ctx, `INSERT INTO loom_users(name,email,password) VALUES(?,?,?) ON CONFLICT(email) DO NOTHING`, c.Name, c.Email, string(hash))
		if err != nil {
			return nil, err
		}
		n, _ := res.RowsAffected()
		if n == 0 {
			return nil, bad(409, "此邮箱已注册")
		}
		id, err = res.LastInsertId()
		if err != nil {
			return nil, err
		}
	} else {
		var hash string
		err := s.DB.QueryRowContext(ctx, "SELECT id,password FROM loom_users WHERE email=?", c.Email).Scan(&id, &hash)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return nil, err
		}
		if hash == "" {
			hash = "$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy"
		}
		if bcrypt.CompareHashAndPassword([]byte(hash), []byte(c.Password)) != nil || id == 0 {
			return nil, bad(401, "邮箱或密码不正确")
		}
	}
	token, csrf := randomToken(), randomToken()
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if old, e := r.Cookie("loom_session"); e == nil {
		if _, err = tx.ExecContext(ctx, "DELETE FROM loom_sessions WHERE token=?", digest(old.Value)); err != nil {
			return nil, err
		}
	}
	if _, err = tx.ExecContext(ctx, "DELETE FROM loom_sessions WHERE expires<=?", now); err != nil {
		return nil, err
	}
	if _, err = tx.ExecContext(ctx, "INSERT INTO loom_sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)", digest(token), id, csrf, now+86400); err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return map[string]any{"cookie": sessionCookie(r, token, 86400), "csrf": csrf, "id": id}, nil
}

// RunJob executes a dedicated PHP entry in an isolated RequestVM, never as a public HTTP route.
func (h *Handler) RunJob(ctx context.Context, payload string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, h.config.Timeout)
	defer cancel()
	env, _ := json.Marshal(requestEnvelope{Method: "POST", Path: "/", Headers: map[string][]string{"content-type": {"application/json"}}, Body: payload})
	r, _ := http.NewRequestWithContext(context.WithValue(ctx, requestKey{}, string(env)), "POST", "http://loom-worker/", nil)
	res, err := h.execute(r)
	if err != nil {
		return "", err
	}
	if ctx.Err() != nil {
		return "", ctx.Err()
	}
	if res.Status >= 300 {
		return "", fmt.Errorf("job returned status %d", res.Status)
	}
	return res.Body, nil
}
