package protocol

import (
	"context"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	_ "google.golang.org/grpc/encoding/gzip"
	"google.golang.org/grpc/reflection"
	reflectionv1 "google.golang.org/grpc/reflection/grpc_reflection_v1"
	reflectionalpha "google.golang.org/grpc/reflection/grpc_reflection_v1alpha"
	"google.golang.org/grpc/status"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/proto"
	"google.golang.org/protobuf/reflect/protoreflect"
	"google.golang.org/protobuf/types/dynamicpb"
)

type Invoke func(context.Context, string, []byte) ([]byte, error)
type serviceMarker interface{}

// Register uses protobuf on the network and ProtoJSON at the application boundary.
func (s *Schema) Register(server *grpc.Server, invoke Invoke, reflect bool) {
	for _, service := range s.Services {
		desc := grpc.ServiceDesc{ServiceName: string(service.FullName()), HandlerType: (*serviceMarker)(nil), Metadata: service.ParentFile().Path()}
		for i := 0; i < service.Methods().Len(); i++ {
			method := service.Methods().Get(i)
			fullName := "/" + string(service.FullName()) + "/" + string(method.Name())
			desc.Methods = append(desc.Methods, grpc.MethodDesc{MethodName: string(method.Name()), Handler: func(impl interface{}, ctx context.Context, decode func(interface{}) error, interceptor grpc.UnaryServerInterceptor) (interface{}, error) {
				request := dynamicpb.NewMessage(method.Input())
				if err := decode(request); err != nil {
					return nil, err
				}
				handle := func(ctx context.Context, value interface{}) (interface{}, error) {
					payload, err := (protojson.MarshalOptions{UseProtoNames: true, Resolver: dynamicpb.NewTypes(s.Files)}).Marshal(value.(proto.Message))
					if err != nil {
						return nil, status.Error(codes.InvalidArgument, "invalid protobuf request")
					}
					result, err := invoke(ctx, fullName, payload)
					if err != nil {
						return nil, err
					}
					response := dynamicpb.NewMessage(method.Output())
					if err := (protojson.UnmarshalOptions{Resolver: dynamicpb.NewTypes(s.Files)}).Unmarshal(result, response); err != nil {
						return nil, status.Error(codes.Internal, "application result does not match protobuf response")
					}
					return response, nil
				}
				if interceptor == nil {
					return handle(ctx, request)
				}
				return interceptor(ctx, request, &grpc.UnaryServerInfo{Server: impl, FullMethod: fullName}, handle)
			}})
		}
		server.RegisterService(&desc, s)
	}
	if reflect {
		options := reflection.ServerOptions{Services: server, DescriptorResolver: s.Files, ExtensionResolver: &extensionResolver{Types: dynamicpb.NewTypes(s.Files), schema: s}}
		reflectionv1.RegisterServerReflectionServer(server, reflection.NewServerV1(options))
		reflectionalpha.RegisterServerReflectionServer(server, reflection.NewServer(options))
	}
}

type extensionResolver struct {
	*dynamicpb.Types
	schema *Schema
}

func (r *extensionResolver) RangeExtensionsByMessage(name protoreflect.FullName, visit func(protoreflect.ExtensionType) bool) {
	more := true
	add := func(extensions protoreflect.ExtensionDescriptors) {
		for i := 0; more && i < extensions.Len(); i++ {
			e := extensions.Get(i)
			if e.ContainingMessage().FullName() == name {
				more = visit(dynamicpb.NewExtensionType(e))
			}
		}
	}
	var walk func(protoreflect.MessageDescriptors)
	walk = func(messages protoreflect.MessageDescriptors) {
		for i := 0; more && i < messages.Len(); i++ {
			m := messages.Get(i)
			add(m.Extensions())
			walk(m.Messages())
		}
	}
	r.schema.Files.RangeFiles(func(file protoreflect.FileDescriptor) bool {
		add(file.Extensions())
		walk(file.Messages())
		return more
	})
}

func NewRequest(method protoreflect.MethodDescriptor, payload []byte) (*dynamicpb.Message, error) {
	message := dynamicpb.NewMessage(method.Input())
	if err := protojson.Unmarshal(payload, message); err != nil {
		return nil, err
	}
	return message, nil
}
