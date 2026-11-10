package extensions

import (
	"context"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
)

type correlationKey struct{}

// CorrelationFromContext reads the correlation id bound for the current execution frame.
func CorrelationFromContext(ctx context.Context) string {
	if ctx == nil {
		return ""
	}
	value, _ := ctx.Value(correlationKey{}).(string)
	return value
}

type withCorrelation struct{}

func (*withCorrelation) GetName() string            { return "Loom\\Native\\withCorrelation" }
func (*withCorrelation) GetModifier() data.Modifier { return data.ModifierPublic }
func (*withCorrelation) GetIsStatic() bool          { return true }
func (*withCorrelation) GetParams() []data.GetValue {
	return []data.GetValue{
		node.NewParameter(nil, "correlationId", 0, nil, data.NewBaseType("string")),
		node.NewParameter(nil, "operation", 1, nil, data.NewBaseType("callable")),
	}
}
func (*withCorrelation) GetVariables() []data.Variable {
	return []data.Variable{
		node.NewVariable(nil, "correlationId", 0, data.NewBaseType("string")),
		node.NewVariable(nil, "operation", 1, data.NewBaseType("callable")),
	}
}
func (*withCorrelation) Call(ctx data.Context) (data.GetValue, data.Control) {
	id, err := utils.ConvertFromIndex[string](ctx, 0)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	op, _ := ctx.GetIndexValue(1)
	child := ctx.GoContext()
	if id != "" {
		child = context.WithValue(child, correlationKey{}, id)
	}
	restore, err := bindExecutionContext(ctx, child)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	defer restore()
	return node.NewCallMethod(nil, op, nil).GetValue(ctx)
}
