package extensions

import (
	"context"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/parser"
	"github.com/php-any/origami/runtime"
	"github.com/php-any/origami/std/container"
)

func TestBindingRestoreDoesNotPinFallbackContext(t *testing.T) {
	vm := runtime.NewVM(parser.NewParser())
	frame := vm.CreateContext(nil)
	defer data.ReleaseContext(frame)
	raw, ok := frame.(interface{ RawGoContext() context.Context })
	if !ok {
		t.Fatal("missing raw execution context")
	}
	if raw.RawGoContext() != nil {
		t.Fatal("fresh frame stored a context")
	}
	database := data.NewClassValue(container.NewContainerClass(), frame)
	restore, err := bindExecutionContext(frame, context.WithValue(frame.GoContext(), modelDatabaseKey{}, database))
	if err != nil {
		t.Fatal(err)
	}
	restore()
	if raw.RawGoContext() != nil {
		t.Fatal("restore pinned GoContext fallback onto the frame")
	}
	got, _ := (&modelDatabase{}).Call(frame)
	if _, ok := got.(*data.NullValue); !ok {
		t.Fatal("binding remained after restore")
	}
}

func TestBindingReachesMethodFrames(t *testing.T) {
	vm := runtime.NewVM(parser.NewParser())
	base := vm.CreateContext(nil)
	defer data.ReleaseContext(base)
	object := data.NewClassValue(container.NewContainerClass(), base)
	frame := data.WrapMethodFrame(base.CreateContext(nil), object, object.Class, object.Class)
	defer frame.ReleasePooled()
	database := data.NewClassValue(container.NewContainerClass(), base)
	restore, err := bindExecutionContext(frame, context.WithValue(frame.GoContext(), modelDatabaseKey{}, database))
	if err != nil {
		t.Fatal(err)
	}
	defer restore()
	got, ctl := (&modelDatabase{}).Call(frame)
	if ctl != nil || got != database {
		t.Fatalf("method frame lost binding: %v %v", got, ctl)
	}
}

func TestModelContextFollowsFramesNotGoroutines(t *testing.T) {
	vm := runtime.NewVM(parser.NewParser())
	base := vm.CreateContext(nil)
	first := data.NewClassValue(container.NewContainerClass(), base)
	second := data.NewClassValue(container.NewContainerClass(), base)
	parent, cancel := context.WithTimeout(context.Background(), time.Minute)
	defer cancel()
	a, b := base.CreateContext(nil), base.CreateContext(nil)
	defer data.ReleaseContext(a)
	defer data.ReleaseContext(b)
	restoreA, err := bindExecutionContext(a, context.WithValue(parent, modelDatabaseKey{}, first))
	if err != nil {
		t.Fatal(err)
	}
	defer restoreA()
	restoreB, err := bindExecutionContext(b, context.WithValue(parent, modelDatabaseKey{}, second))
	if err != nil {
		t.Fatal(err)
	}
	defer restoreB()
	// Both contexts are constructed on the same goroutine, then handed to
	// different workers. No global ambient binding may determine the result.
	frames := []data.Context{a.CreateContext(nil), b.CreateBaseContext()}
	objects := []*data.ClassValue{first, second}
	var workers sync.WaitGroup
	for i, frame := range frames {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for range 100 {
				got, ctl := (&modelDatabase{}).Call(frame)
				if ctl != nil || got != objects[i] {
					t.Errorf("binding crossed execution contexts: %v, %v", got, ctl)
					return
				}
			}
		}()
	}
	workers.Wait()
	got, _ := (&modelDatabase{}).Call(base)
	if _, ok := got.(*data.NullValue); !ok {
		t.Fatal("binding mutated the caller")
	}
	cancel()
	for _, frame := range frames {
		if frame.GoContext().Err() != context.Canceled {
			t.Fatal("lost cancellation on child frame")
		}
		if deadline, ok := frame.GoContext().Deadline(); !ok || deadline.IsZero() {
			t.Fatal("lost deadline on child frame")
		}
		data.ReleaseContext(frame)
	}
}

func TestModelContextSurvivesInclude(t *testing.T) {
	vm := runtime.NewVM(parser.NewParser())
	vm.AddFunc(&modelDatabase{})
	frame := vm.CreateContext(nil)
	defer data.ReleaseContext(frame)
	database := data.NewClassValue(container.NewContainerClass(), frame)
	restore, err := bindExecutionContext(frame, context.WithValue(frame.GoContext(), modelDatabaseKey{}, database))
	if err != nil {
		t.Fatal(err)
	}
	defer restore()
	file := filepath.Join(t.TempDir(), "included.php")
	if err := os.WriteFile(file, []byte("<?php return \\Loom\\Native\\modelDatabase();"), 0600); err != nil {
		t.Fatal(err)
	}
	value, ctl := vm.LoadInCallerContext(frame, file)
	if returned, ok := ctl.(data.ReturnControl); ok {
		value, ctl = returned.ReturnValue(), nil
	}
	if ctl != nil || value != database {
		t.Fatalf("included file lost model binding: %v %v", value, ctl)
	}
}

type boundaryProbe struct {
	data.FuncStmt
	check func(data.Context)
}

func (p *boundaryProbe) Call(ctx data.Context) (data.GetValue, data.Control) {
	p.check(ctx)
	return nil, nil
}

func TestSpawnDropsLockOwnershipButKeepsModelBinding(t *testing.T) {
	vm := runtime.NewVM(parser.NewParser())
	frame := vm.CreateContext(nil)
	defer data.ReleaseContext(frame)
	owner := &singletonOwner{}
	database := data.NewClassValue(container.NewContainerClass(), frame)
	parent := context.WithValue(frame.GoContext(), modelDatabaseKey{}, database)
	parent = context.WithValue(parent, singletonOwnerKey{}, owner)
	restore, err := bindExecutionContext(frame, parent)
	if err != nil {
		t.Fatal(err)
	}
	defer restore()
	called := false
	boundary := &spawnBoundary{FuncStmt: &boundaryProbe{check: func(ctx data.Context) {
		called = true
		inherited, _ := ctx.GoContext().Value(singletonOwnerKey{}).(*singletonOwner)
		if inherited != nil || ctx.GoContext().Value(modelDatabaseKey{}) != database {
			t.Fatal("async child inherited lock ownership or lost database binding")
		}
	}}}
	if _, ctl := boundary.Call(frame); ctl != nil || !called {
		t.Fatalf("spawn was not delegated: %v", ctl)
	}
	if frame.GoContext().Value(singletonOwnerKey{}) != owner {
		t.Fatal("spawn changed caller lock ownership")
	}
}
