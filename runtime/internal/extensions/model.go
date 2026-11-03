package extensions

import (
	"context"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
)

type modelDatabaseKey struct{}

type modelDatabase struct{}

func (*modelDatabase) GetName() string               { return "Loom\\Native\\modelDatabase" }
func (*modelDatabase) GetParams() []data.GetValue    { return nil }
func (*modelDatabase) GetVariables() []data.Variable { return nil }
func (*modelDatabase) Call(ctx data.Context) (data.GetValue, data.Control) {
	if db, ok := ctx.GoContext().Value(modelDatabaseKey{}).(*data.ClassValue); ok {
		return db, nil
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
	restore, err := bindExecutionContext(ctx, context.WithValue(ctx.GoContext(), modelDatabaseKey{}, object))
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	defer restore()
	return node.NewCallMethod(nil, op, nil).GetValue(ctx)
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
