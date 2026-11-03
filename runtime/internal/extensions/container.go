package extensions

import (
	"context"
	"sync"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
)

// Locks cover a whole singleton dependency graph, including nested resolutions.
// Entries live only while in use, so completed applications are not retained.
type singletonGate struct {
	owner *singletonOwner
	depth int
	users int
}

var singletonGates = struct {
	sync.Mutex
	changed *sync.Cond
	entries map[data.ClassStmt]*singletonGate
}{entries: make(map[data.ClassStmt]*singletonGate)}

func init() { singletonGates.changed = sync.NewCond(&singletonGates.Mutex) }

type singletonOwner struct{ token byte }
type singletonOwnerKey struct{}

func lockSingleton(key data.ClassStmt, owner *singletonOwner) func() {
	singletonGates.Lock()
	gate := singletonGates.entries[key]
	if gate == nil {
		gate = &singletonGate{}
		singletonGates.entries[key] = gate
	}
	gate.users++
	for gate.depth > 0 && gate.owner != owner {
		singletonGates.changed.Wait()
	}
	gate.owner = owner
	gate.depth++
	singletonGates.Unlock()
	return func() {
		singletonGates.Lock()
		defer singletonGates.Unlock()
		gate.depth--
		gate.users--
		if gate.depth == 0 {
			gate.owner = nil
		}
		if gate.users == 0 {
			delete(singletonGates.entries, key)
		}
		singletonGates.changed.Broadcast()
	}
}

type withSingletonLock struct{}

func (*withSingletonLock) GetName() string { return "Loom\\Native\\withSingletonLock" }
func (*withSingletonLock) GetParams() []data.GetValue {
	return []data.GetValue{data.NewParameter("container", 0), data.NewParameter("operation", 1)}
}
func (*withSingletonLock) GetVariables() []data.Variable {
	return []data.Variable{data.NewVariable("container", 0, data.NewBaseType("object")), data.NewVariable("operation", 1, data.NewBaseType("callable"))}
}
func (*withSingletonLock) Call(ctx data.Context) (data.GetValue, data.Control) {
	value, _ := ctx.GetIndexValue(0)
	container, ok := modelObject(value)
	if !ok || container.Class.GetName() != "Container\\Container" {
		return nil, utils.NewThrowf("Expected a native service container")
	}
	owner, _ := ctx.GoContext().Value(singletonOwnerKey{}).(*singletonOwner)
	if owner == nil {
		owner = &singletonOwner{}
	}
	release := lockSingleton(container.Class, owner)
	defer release()
	operation, _ := ctx.GetIndexValue(1)
	restore, err := bindExecutionContext(ctx, context.WithValue(ctx.GoContext(), singletonOwnerKey{}, owner))
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	defer restore()
	return node.NewCallMethod(nil, operation, nil).GetValue(ctx)
}
