package extensions

import (
	"context"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/std"
	"github.com/php-any/origami/utils"
)

// LoadStandardLibrary preserves std implementations. Only the host's synchronous
// singleton-resolution ownership must stop at an asynchronous call boundary.
func LoadStandardLibrary(vm data.VM) { std.Load(&standardRegistration{VM: vm}) }

type standardRegistration struct{ data.VM }

func (vm *standardRegistration) AddFunc(fn data.FuncStmt) data.Control {
	if fn.GetName() == "spawn" {
		fn = &spawnBoundary{FuncStmt: fn}
	}
	return vm.VM.AddFunc(fn)
}

type spawnBoundary struct{ data.FuncStmt }

func (f *spawnBoundary) Call(ctx data.Context) (data.GetValue, data.Control) {
	if ctx.GoContext().Value(singletonOwnerKey{}) == nil {
		return f.FuncStmt.Call(ctx)
	}
	child := context.WithValue(ctx.GoContext(), singletonOwnerKey{}, (*singletonOwner)(nil))
	restore, err := bindExecutionContext(ctx, child)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	defer restore()
	return f.FuncStmt.Call(ctx)
}
