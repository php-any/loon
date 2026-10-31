package host

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/php-any/loom/framework"
	"github.com/php-any/loom/protocol"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"
	"log"
)

func registerApplicationGRPC(ctx context.Context, server *grpc.Server, handler *loom.Handler, config *protocolConfig) error {
	config, err := withGeneratedGRPC(config)
	if err != nil {
		return err
	}
	schema, err := protocol.Load(ctx, config.ImportPaths, config.ProtoFiles)
	if err != nil {
		return err
	}
	schema.Register(server, func(ctx context.Context, method string, payload []byte) ([]byte, error) {
		envelope, err := json.Marshal(map[string]any{"method": method, "request": json.RawMessage(payload)})
		if err != nil {
			return nil, status.Error(codes.Internal, "invalid request envelope")
		}
		result, err := handler.RunMessage(ctx, envelope)
		if err != nil {
			if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
				return nil, status.FromContextError(err).Err()
			}
			if errors.Is(err, loom.ErrMessageBusy) {
				return nil, status.Error(codes.ResourceExhausted, "server busy")
			}
			log.Printf("gRPC execution: %v", err)
			return nil, status.Error(codes.Internal, "message execution failed")
		}
		var response struct {
			Data  json.RawMessage `json:"data"`
			Error *struct {
				Code    string `json:"code"`
				Message string `json:"message"`
			} `json:"error"`
		}
		if err := json.Unmarshal(result, &response); err != nil {
			return nil, status.Error(codes.Internal, "invalid application envelope")
		}
		if response.Error != nil {
			allowed := map[string]codes.Code{"invalid_argument": codes.InvalidArgument, "not_found": codes.NotFound, "already_exists": codes.AlreadyExists, "permission_denied": codes.PermissionDenied, "unauthenticated": codes.Unauthenticated, "failed_precondition": codes.FailedPrecondition, "unimplemented": codes.Unimplemented}
			code, ok := allowed[response.Error.Code]
			if !ok {
				return nil, status.Error(codes.Internal, "invalid application error code")
			}
			return nil, status.Error(code, response.Error.Message)
		}
		if response.Data == nil {
			return nil, status.Error(codes.Internal, "missing application data")
		}
		return response.Data, nil
	}, config.Reflection)
	return nil
}
