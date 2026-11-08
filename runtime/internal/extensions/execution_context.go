package extensions

import (
	"context"
	"fmt"

	"github.com/php-any/origami/data"
)

type executionFrame interface {
	SetGoContext(context.Context)
	RawGoContext() context.Context
}

// Bind only the current native call frame. Origami propagates the immutable
// context to child frames, includes and callbacks, and clears pooled frames.
// The saved value is the frame's stored context, not GoContext()'s deadline
// fallback; restoring the fallback would hide a later request cancellation.
func bindExecutionContext(ctx data.Context, goContext context.Context) (func(), error) {
	frame, ok := findExecutionFrame(ctx)
	if !ok {
		return nil, fmt.Errorf("Origami execution context support is required; the Origami checkout must provide SetGoContext and RawGoContext")
	}
	previous := frame.RawGoContext()
	frame.SetGoContext(goContext)
	return func() { frame.SetGoContext(previous) }, nil
}

func findExecutionFrame(ctx data.Context) (executionFrame, bool) {
	for i := 0; ctx != nil && i < 8; i++ {
		if frame, ok := ctx.(executionFrame); ok {
			return frame, true
		}
		switch current := ctx.(type) {
		case *data.ClassMethodContext:
			ctx = current.Context
		case *data.BoundContext:
			ctx = current.Context
		case *data.ClassValue:
			ctx = current.Context
		default:
			return nil, false
		}
	}
	return nil, false
}
