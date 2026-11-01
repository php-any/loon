package extensions

// 将原生 ORM 的 SQL 传输适配到 Loom 现有的作用域 PDO 服务。
// Origami 仍负责构建 SQL 和填充实体。不会额外打开物理
// 连接，因此 ORM 与原始 SQL 参与同一个事务。
import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"io"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/std/database"
	sqlpkg "github.com/php-any/origami/std/database/sql"
	"github.com/php-any/origami/utils"
	"runtime"
	"strings"
	"sync"
)

var modelScopes sync.Map

func modelScopeID() string {
	var buffer [64]byte
	n := runtime.Stack(buffer[:], false)
	return strings.Fields(string(buffer[:n]))[1]
}

type modelDatabase struct{}

func (*modelDatabase) GetName() string               { return "Loom\\Native\\modelDatabase" }
func (*modelDatabase) GetParams() []data.GetValue    { return nil }
func (*modelDatabase) GetVariables() []data.Variable { return nil }
func (*modelDatabase) Call(ctx data.Context) (data.GetValue, data.Control) {
	if db, ok := modelScopes.Load(modelScopeID()); ok {
		return db.(*data.ClassValue), nil
	}
	return data.NewNullValue(), nil
}

type withModelDatabase struct{}

func (*withModelDatabase) GetName() string { return "Loom\\Native\\withModelDatabase" }
func (*withModelDatabase) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("database", 0), data.NewParameter("operation", 1)}
}
func (*withModelDatabase) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("database", 0, data.NewBaseType("object")), data.NewVariable("operation", 1, data.NewBaseType("callable"))}
}
func (*withModelDatabase) Call(ctx data.Context) (data.GetValue, data.Control) {
	db, _ := ctx.GetIndexValue(0)
	op, _ := ctx.GetIndexValue(1)
	object, ok := modelObject(db)
	if !ok {
		return nil, utils.NewThrowf("Expected a model database service")
	}
	db = object
	id := modelScopeID()
	previous, hadPrevious := modelScopes.Load(id)
	modelScopes.Store(id, db)
	defer func() {
		if hadPrevious {
			modelScopes.Store(id, previous)
		} else {
			modelScopes.Delete(id)
		}
	}()
	return node.NewCallMethod(nil, op, nil).GetValue(ctx)
}

type modelConnection struct{}

func (*modelConnection) GetName() string { return "Loom\\Native\\modelConnection" }
func (*modelConnection) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("name", 0), data.NewParameter("database", 1)}
}
func (*modelConnection) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("name", 0, data.NewBaseType("string")), data.NewVariable("database", 1, data.NewBaseType("object"))}
}
func (*modelConnection) Call(ctx data.Context) (data.GetValue, data.Control) {
	name, _ := ctx.GetIndexValue(0)
	value, _ := ctx.GetIndexValue(1)
	db, ok := modelObject(value)
	if !ok {
		return nil, utils.NewThrowf("Expected database service, got %T", value)
	}
	adapter := sql.OpenDB(&modelConnector{db})
	adapter.SetMaxOpenConns(1)
	database.GetConnectionManager().AddConnection(name.(data.AsString).AsString(), adapter)
	return data.NewClassValue(sqlpkg.NewDBClassFrom(adapter), ctx), nil
}

type modelConnector struct{ db *data.ClassValue }

func (c *modelConnector) Connect(context.Context) (driver.Conn, error) { return &modelConn{c.db}, nil }
func (c *modelConnector) Driver() driver.Driver                        { return modelDriver{} }

type modelDriver struct{}

func (modelDriver) Open(string) (driver.Conn, error) {
	return nil, fmt.Errorf("Use the scoped connector")
}

type modelConn struct{ db *data.ClassValue }

func (*modelConn) Close() error { return nil }
func (*modelConn) Prepare(string) (driver.Stmt, error) {
	return nil, fmt.Errorf("Prepared transport is unsupported")
}
func (*modelConn) Begin() (driver.Tx, error) {
	return nil, fmt.Errorf("Use Loom Database transactions")
}

func modelCall(db *data.ClassValue, method string, query string, args []driver.NamedValue) (data.Value, error) {
	m, ok := db.GetMethod(method)
	if !ok {
		return nil, fmt.Errorf("Missing database method %s", method)
	}
	values := make([]data.Value, len(args))
	for i, arg := range args {
		values[i] = modelValue(arg.Value)
	}
	frame := data.WrapMethodFrame(db.CreateContext(m.GetVariables()), db, data.MethodDeclaringClass(db.GetVM(), db.Class, method), db.Class)
	defer frame.ReleasePooled()
	if ctl := data.BindDeclaredArgs(frame, m, []data.Value{data.NewStringValue(query), data.NewArrayValue(values)}); ctl != nil {
		return nil, fmt.Errorf("Database arguments: %v", ctl)
	}
	result, ctl := m.Call(frame)
	if ctl != nil {
		if ret, ok := ctl.(data.ReturnControl); ok {
			return ret.ReturnValue(), nil
		}
		return nil, fmt.Errorf("Database operation failed: %v", ctl)
	}
	value, ok := result.(data.Value)
	if !ok {
		return nil, fmt.Errorf("Invalid database result")
	}
	return value, nil
}

type modelResult struct{ id, affected int64 }

func (r modelResult) LastInsertId() (int64, error) { return r.id, nil }
func (r modelResult) RowsAffected() (int64, error) { return r.affected, nil }
func (c *modelConn) ExecContext(ctx context.Context, query string, args []driver.NamedValue) (driver.Result, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	value, err := modelCall(c.db, "execute", query, args)
	if err != nil {
		return nil, err
	}
	arr, ok := value.(*data.ArrayValue)
	if !ok {
		return nil, fmt.Errorf("Expected execute array")
	}
	r := modelResult{}
	view := arr.View()
	for i := 0; i < view.Len(); i++ {
		slot := view.At(i)
		key := slot.PHPArrayKey(i).(data.AsString).AsString()
		v := database.ConvertValueToGoType(slot.ReadValue())
		n, ok := v.(int)
		if !ok {
			return nil, fmt.Errorf("Expected integer result")
		}
		if key == "id" {
			r.id = int64(n)
		}
		if key == "affected" {
			r.affected = int64(n)
		}
	}
	return r, nil
}

type modelRows struct {
	columns []string
	rows    [][]driver.Value
	index   int
}

func (r *modelRows) Columns() []string { return r.columns }
func (*modelRows) Close() error        { return nil }
func (r *modelRows) Next(dest []driver.Value) error {
	if r.index >= len(r.rows) {
		return io.EOF
	}
	copy(dest, r.rows[r.index])
	r.index++
	return nil
}
func (c *modelConn) QueryContext(ctx context.Context, query string, args []driver.NamedValue) (driver.Rows, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	value, err := modelCall(c.db, "query", query, args)
	if err != nil {
		return nil, err
	}
	arr, ok := value.(*data.ArrayValue)
	if !ok {
		return nil, fmt.Errorf("Expected query array")
	}
	result := &modelRows{}
	view := arr.View()
	for i := 0; i < view.Len(); i++ {
		row, ok := view.At(i).ReadValue().(*data.ArrayValue)
		if !ok {
			return nil, fmt.Errorf("Expected row array")
		}
		fields := row.View()
		values := make([]driver.Value, fields.Len())
		for j := 0; j < fields.Len(); j++ {
			slot := fields.At(j)
			if i == 0 {
				result.columns = append(result.columns, slot.PHPArrayKey(j).(data.AsString).AsString())
			}
			v := database.ConvertValueToGoType(slot.ReadValue())
			if n, ok := v.(int); ok {
				v = int64(n)
			}
			values[j] = v
		}
		result.rows = append(result.rows, values)
	}
	return result, nil
}

func modelValue(value any) data.Value {
	switch v := value.(type) {
	case nil:
		return data.NewNullValue()
	case int64:
		return data.NewIntValue(int(v))
	case float64:
		return data.NewFloatValue(v)
	case bool:
		return data.NewBoolValue(v)
	case []byte:
		return data.NewStringValue(string(v))
	case string:
		return data.NewStringValue(v)
	default:
		return data.NewStringValue(fmt.Sprint(v))
	}
}

func modelObject(value data.Value) (*data.ClassValue, bool) {
	switch v := value.(type) {
	case *data.ThisValue:
		return v.ClassValue.InstanceIdentity(), true
	case *data.ZValValue:
		return modelObject(v.ZVal.ReadValue())
	case *data.ClassValue:
		return v.InstanceIdentity(), true
	case *data.ClassMethodContext:
		return v.ClassValue.InstanceIdentity(), true
	}
	return nil, false
}
