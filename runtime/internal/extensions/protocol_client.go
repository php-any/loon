package extensions

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"strings"
	"time"

	"github.com/php-any/origami/data"
	"github.com/php-any/origami/node"
	"github.com/php-any/origami/utils"
	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/metadata"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/types/known/wrapperspb"
)

const maxReply = 65536

// 每次交互使用独立连接，并设置包含拨号阶段的总截止时间。
func protocolExchange(parent context.Context, protocol, address, method, payload string) map[string]any {
	start := time.Now()
	result := map[string]any{"ok": false, "status": "ERROR"}
	ctx, cancel := context.WithTimeout(parent, 3*time.Second)
	defer cancel()
	var response json.RawMessage
	var err error
	correlation := CorrelationFromContext(parent)
	switch protocol {
	case "tcp":
		payload = stampCorrelationPayload(payload, correlation)
		if len(payload) > 4096 || strings.ContainsAny(payload, "\r\n") {
			err = fmt.Errorf("TCP requires one JSON line of at most 4096 bytes")
			break
		}
		var conn net.Conn
		conn, err = (&net.Dialer{}).DialContext(ctx, "tcp", address)
		if err != nil {
			break
		}
		defer conn.Close()
		stop := context.AfterFunc(ctx, func() { conn.Close() })
		defer stop()
		deadline, _ := ctx.Deadline()
		conn.SetDeadline(deadline)
		if _, err = io.WriteString(conn, payload+"\n"); err != nil {
			break
		}
		var line []byte
		line, err = bufio.NewReaderSize(io.LimitReader(conn, maxReply+1), maxReply+1).ReadBytes('\n')
		if len(line) > maxReply {
			err = fmt.Errorf("TCP response exceeds 64 KiB")
		}
		if err == nil && !json.Valid(line) {
			err = fmt.Errorf("TCP response is not JSON")
		}
		response = line
	case "grpc":
		payload = stampCorrelationPayload(payload, correlation)
		request := new(wrapperspb.StringValue)
		if err = protojson.Unmarshal([]byte(payload), request); err != nil {
			break
		}
		var client *grpc.ClientConn
		client, err = grpc.NewClient("passthrough:///"+address, grpc.WithTransportCredentials(insecure.NewCredentials()), grpc.WithDefaultCallOptions(grpc.MaxCallRecvMsgSize(maxReply), grpc.MaxCallSendMsgSize(4096)))
		if err != nil {
			break
		}
		defer client.Close()
		reply := new(wrapperspb.StringValue)
		invokeCtx := ctx
		if correlation != "" {
			invokeCtx = metadata.NewOutgoingContext(ctx, metadata.Pairs("x-loom-correlation-id", correlation))
		}
		err = client.Invoke(invokeCtx, method, request, reply)
		if err != nil {
			result["status"] = status.Code(err).String()
			break
		}
		response, err = protojson.Marshal(reply)
	default:
		err = fmt.Errorf("unsupported protocol")
	}
	if err != nil {
		result["error"] = err.Error()
	} else {
		result["ok"] = true
		result["status"] = "OK"
		result["response"] = response
	}
	result["elapsed_ms"] = float64(time.Since(start).Microseconds()) / 1000
	return result
}

type protocolRequest struct{}

func (*protocolRequest) GetName() string            { return "Loom\\Native\\protocolRequest" }
func (*protocolRequest) GetModifier() data.Modifier { return data.ModifierPublic }
func (*protocolRequest) GetIsStatic() bool          { return true }
func (*protocolRequest) GetReturnType() data.Types  { return data.NewBaseType("string") }
func (*protocolRequest) GetParams() []data.GetValue {
	out := []data.GetValue{}
	for i, name := range []string{"protocol", "address", "method", "payload"} {
		out = append(out, node.NewParameter(nil, name, i, nil, data.NewBaseType("string")))
	}
	return out
}
func (*protocolRequest) GetVariables() []data.Variable {
	out := []data.Variable{}
	for i, name := range []string{"protocol", "address", "method", "payload"} {
		out = append(out, node.NewVariable(nil, name, i, data.NewBaseType("string")))
	}
	return out
}
func (*protocolRequest) Call(ctx data.Context) (data.GetValue, data.Control) {
	args := make([]string, 4)
	for i := range args {
		var err error
		args[i], err = utils.ConvertFromIndex[string](ctx, i)
		if err != nil {
			return nil, utils.NewThrow(err)
		}
	}
	result := protocolExchange(ctx.GoContext(), args[0], args[1], args[2], args[3])
	encoded, err := json.Marshal(result)
	if err != nil {
		return nil, utils.NewThrow(err)
	}
	return data.NewStringValue(string(encoded)), nil
}
