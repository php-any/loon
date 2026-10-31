// Package protocol loads protobuf contracts without generated application code.
package protocol

import (
	"context"
	"fmt"
	"github.com/bufbuild/protocompile"
	"google.golang.org/protobuf/reflect/protoreflect"
	"google.golang.org/protobuf/reflect/protoregistry"
)

type Schema struct {
	Files    *protoregistry.Files
	Services []protoreflect.ServiceDescriptor
}

// Load links selected files and their imports. Only selected services are exposed.
func Load(ctx context.Context, roots, files []string) (*Schema, error) {
	if len(roots) == 0 || len(files) == 0 {
		return nil, fmt.Errorf("proto import paths and files are required")
	}
	compiler := protocompile.Compiler{Resolver: protocompile.WithStandardImports(&protocompile.SourceResolver{ImportPaths: roots})}
	compiled, err := compiler.Compile(ctx, files...)
	if err != nil {
		return nil, fmt.Errorf("compile proto: %w", err)
	}
	schema := &Schema{Files: new(protoregistry.Files)}
	seen := map[string]bool{}
	var register func(protoreflect.FileDescriptor) error
	register = func(file protoreflect.FileDescriptor) error {
		if seen[file.Path()] {
			return nil
		}
		seen[file.Path()] = true
		for i := 0; i < file.Imports().Len(); i++ {
			if err := register(file.Imports().Get(i).FileDescriptor); err != nil {
				return err
			}
		}
		return schema.Files.RegisterFile(file)
	}
	selected := map[protoreflect.FullName]bool{}
	for _, file := range compiled {
		if err := register(file); err != nil {
			return nil, err
		}
		for i := 0; i < file.Services().Len(); i++ {
			service := file.Services().Get(i)
			if selected[service.FullName()] {
				continue
			}
			selected[service.FullName()] = true
			for j := 0; j < service.Methods().Len(); j++ {
				method := service.Methods().Get(j)
				if method.IsStreamingClient() || method.IsStreamingServer() {
					return nil, fmt.Errorf("streaming RPC is not supported: %s", method.FullName())
				}
			}
			schema.Services = append(schema.Services, service)
		}
	}
	if len(schema.Services) == 0 {
		return nil, fmt.Errorf("selected proto files declare no services")
	}
	return schema, nil
}

func (s *Schema) Method(service, method string) (protoreflect.MethodDescriptor, error) {
	for _, candidate := range s.Services {
		if string(candidate.FullName()) == service {
			if result := candidate.Methods().ByName(protoreflect.Name(method)); result != nil {
				return result, nil
			}
		}
	}
	return nil, fmt.Errorf("RPC not found: %s/%s", service, method)
}
