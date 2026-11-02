package extensions

import (
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/php-any/origami/std/container"
)

func TestSingletonGateSerializesAndReenters(t *testing.T) {
	key := container.NewContainerClass()
	var active atomic.Int32
	var failed atomic.Bool
	var workers sync.WaitGroup
	for i := 0; i < 16; i++ {
		workers.Add(1)
		go func(i int) {
			defer workers.Done()
			owner := fmt.Sprint(i)
			release := lockSingleton(key, owner)
			defer release()
			if active.Add(1) != 1 {
				failed.Store(true)
			}
			nested := lockSingleton(key, owner)
			time.Sleep(time.Millisecond)
			nested()
			active.Add(-1)
		}(i)
	}
	workers.Wait()
	if failed.Load() {
		t.Fatal("singleton dependency graphs overlapped")
	}
	singletonGates.Lock()
	defer singletonGates.Unlock()
	if _, retained := singletonGates.entries[key]; retained {
		t.Fatal("gate retained the completed application")
	}
}

func TestSingletonGatesArePerApplication(t *testing.T) {
	first := lockSingleton(container.NewContainerClass(), "first")
	defer first()
	second := lockSingleton(container.NewContainerClass(), "second")
	second()
}
