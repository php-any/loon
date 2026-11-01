from pathlib import Path
root=Path('D:/gitcode.com/origami')
def put(path,s):
 p=root/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(s,encoding='utf-8')
put('std/net/grpc/schema.go',r'''package grpc
import (
 "context"
 "encoding/json"
 "fmt"
 "github.com/bufbuild/protocompile"
 "google.golang.org/protobuf/reflect/protoreflect"
 "google.golang.org/protobuf/reflect/protoregistry"
 "google.golang.org/protobuf/types/dynamicpb"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/std/net/internal/script"
 "github.com/php-any/origami/utils"
)
type schema struct { files *protoregistry.Files; types *dynamicpb.Types; services []protoreflect.ServiceDescriptor }
func compile(ctx context.Context,files,paths []string)(*schema,error){
 if len(files)==0{return nil,fmt.Errorf("at least one proto file is required")}
 compiler:=protocompile.Compiler{Resolver:protocompile.WithStandardImports(&protocompile.SourceResolver{ImportPaths:paths})}
 result,e:=compiler.Compile(ctx,files...);if e!=nil{return nil,e}
 s:=&schema{files:new(protoregistry.Files)};seen:=map[string]bool{}
 var add func(protoreflect.FileDescriptor)error
 add=func(f protoreflect.FileDescriptor)error{if seen[f.Path()]{return nil};seen[f.Path()]=true;for i:=0;i<f.Imports().Len();i++{if e:=add(f.Imports().Get(i).FileDescriptor);e!=nil{return e}};return s.files.RegisterFile(f)}
 for _,f:=range result{if e:=add(f);e!=nil{return nil,e};for i:=0;i<f.Services().Len();i++{s.services=append(s.services,f.Services().Get(i))}}
 s.types=dynamicpb.NewTypes(s.files);return s,nil
}
func newSchema()data.ClassStmt{
 s:=&schema{};c:=&script.Class{Name:"Net\\Grpc\\Schema",Factory:newSchema,Source:s}
 c.Methods=[]data.Method{
 &script.Method{Name:"__construct",Args:[]string{"files","importPaths"},Run:func(ctx data.Context)(data.GetValue,error){files,e:=utils.ConvertFromIndex[[]string](ctx,0);if e!=nil{return nil,e};paths,e:=utils.ConvertFromIndex[[]string](ctx,1);if e!=nil{return nil,e};compiled,e:=compile(ctx.GoContext(),files,paths);if e==nil{*s=*compiled};return nil,e}},
 &script.Method{Name:"methodsJson",Run:func(data.Context)(data.GetValue,error){methods:=[]map[string]any{};for _,svc:=range s.services{for i:=0;i<svc.Methods().Len();i++{m:=svc.Methods().Get(i);methods=append(methods,map[string]any{"service":string(svc.FullName()),"method":string(m.Name()),"path":"/"+string(svc.FullName())+"/"+string(m.Name()),"clientStreaming":m.IsStreamingClient(),"serverStreaming":m.IsStreamingServer()})}};b,e:=json.Marshal(methods);return data.NewStringValue(string(b)),e}},
 };return c
}
''')
put('std/net/grpc/server.go',r'''// Package grpc exposes general unary gRPC transport and dynamic protobuf schemas.
// 应用路由、校验和状态策略仍由脚本回调处理。
package grpc
import (
 "context"
 "fmt"
 "net"
 "time"
 grpcsrc "google.golang.org/grpc"
 "google.golang.org/grpc/codes"
 "google.golang.org/grpc/status"
 "google.golang.org/grpc/keepalive"
 _ "google.golang.org/grpc/encoding/gzip"
 "google.golang.org/grpc/reflection"
 rv1 "google.golang.org/grpc/reflection/grpc_reflection_v1"
 ralpha "google.golang.org/grpc/reflection/grpc_reflection_v1alpha"
 "google.golang.org/protobuf/encoding/protojson"
 "google.golang.org/protobuf/proto"
 "google.golang.org/protobuf/reflect/protoreflect"
 "google.golang.org/protobuf/types/dynamicpb"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/std/net/internal/script"
 "github.com/php-any/origami/utils"
)
func Load(vm data.VM){vm.AddClass(newSchema());vm.AddClass(newServer());vm.AddClass(callClass(nil))}
var defaults=map[string]int{"maxConnections":128,"maxConcurrentStreams":32,"maxCalls":128,"maxRecvBytes":1048576,"maxSendBytes":4194304,"maxHeaderBytes":16384,"requestTimeoutMs":10000,"connectionTimeoutMs":5000,"idleTimeoutMs":30000,"maxConnectionAgeMs":300000}
func newServer()data.ClassStmt{
 address:="";var sch *schema;opts:=defaults;reflectEnabled:=false
 c:=&script.Class{Name:"Net\\Grpc\\Server",Factory:newServer}
 c.Methods=[]data.Method{
 &script.Method{Name:"__construct",Args:[]string{"address","schema"},Run:func(ctx data.Context)(data.GetValue,error){var e error;address,e=utils.ConvertFromIndex[string](ctx,0);if e!=nil{return nil,e};sch,e=utils.ConvertFromIndex[*schema](ctx,1);return nil,e}},
 &script.Method{Name:"configure",Args:[]string{"options"},Run:func(ctx data.Context)(data.GetValue,error){v,e:=script.Options(ctx,0,opts);if e==nil{opts=v};return nil,e}},
 &script.Method{Name:"reflection",Args:[]string{"enabled"},Run:func(ctx data.Context)(data.GetValue,error){var e error;reflectEnabled,e=utils.ConvertFromIndex[bool](ctx,0);return nil,e}},
 &script.Method{Name:"serve",Args:[]string{"callback"},Run:func(ctx data.Context)(data.GetValue,error){fn,e:=script.Callback(ctx,0);if e!=nil{return nil,e};if sch==nil||sch.files==nil{return nil,fmt.Errorf("schema not initialized")}
 srv,e:=makeServer(sch,opts,reflectEnabled,func(gc context.Context,call *rpcCall)error{return script.Invoke(ctx.GetVM(),fn,gc,func(c data.Context)[]data.Value{return []data.Value{data.NewProxyValue(callClass(call),c)}})});if e!=nil{return nil,e}
 ln,e:=net.Listen("tcp",address);if e!=nil{return nil,e};defer ln.Close();defer srv.Stop();stop:=context.AfterFunc(ctx.GoContext(),srv.Stop);defer stop();return nil,srv.Serve(script.LimitListener(ln,opts["maxConnections"]))}},
 };return c
}
type rpcCall struct {method string;input string;output string;err error;answered bool;ctx context.Context}
func callClass(call *rpcCall)data.ClassStmt{
 c:=&script.Class{Name:"Net\\Grpc\\Call",Source:call}
 valid:=func()error{if call==nil{return fmt.Errorf("uninitialized RPC")};if call.answered{return fmt.Errorf("RPC already completed")};return call.ctx.Err()}
 c.Methods=[]data.Method{
 &script.Method{Name:"method",Run:func(data.Context)(data.GetValue,error){if call==nil{return nil,fmt.Errorf("uninitialized RPC")};return data.NewStringValue(call.method),nil}},
 &script.Method{Name:"requestJson",Run:func(data.Context)(data.GetValue,error){if call==nil{return nil,fmt.Errorf("uninitialized RPC")};return data.NewStringValue(call.input),nil}},
 &script.Method{Name:"respondJson",Args:[]string{"json"},Run:func(ctx data.Context)(data.GetValue,error){if e:=valid();e!=nil{return nil,e};v,e:=utils.ConvertFromIndex[string](ctx,0);if e==nil{call.output=v;call.answered=true};return nil,e}},
 &script.Method{Name:"fail",Args:[]string{"code","message"},Run:func(ctx data.Context)(data.GetValue,error){if e:=valid();e!=nil{return nil,e};code,e:=utils.ConvertFromIndex[int](ctx,0);if e!=nil{return nil,e};if code<1||code>16{return nil,fmt.Errorf("gRPC error code must be 1..16")};message,e:=utils.ConvertFromIndex[string](ctx,1);if e==nil{call.err=status.Error(codes.Code(code),message);call.answered=true};return nil,e}},
 };return c
}
func makeServer(s *schema,o map[string]int,reflectionEnabled bool,callback func(context.Context,*rpcCall)error)(*grpcsrc.Server,error){
 slots:=make(chan struct{},o["maxCalls"])
 acquire:=func()bool{select{case slots<-struct{}{}:return true;default:return false}}
 unary:=func(ctx context.Context,req any,info *grpcsrc.UnaryServerInfo,next grpcsrc.UnaryHandler)(any,error){if !acquire(){return nil,status.Error(codes.ResourceExhausted,"concurrent call limit")};defer func(){<-slots}();ctx,cancel:=context.WithTimeout(ctx,time.Duration(o["requestTimeoutMs"])*time.Millisecond);defer cancel();return next(ctx,req)}
 streams:=func(srv any,ss grpcsrc.ServerStream,info *grpcsrc.StreamServerInfo,next grpcsrc.StreamHandler)error{if !acquire(){return status.Error(codes.ResourceExhausted,"concurrent call limit")};defer func(){<-slots}();return next(srv,ss)}
 srv:=grpcsrc.NewServer(grpcsrc.MaxRecvMsgSize(o["maxRecvBytes"]),grpcsrc.MaxSendMsgSize(o["maxSendBytes"]),grpcsrc.MaxHeaderListSize(uint32(o["maxHeaderBytes"])),grpcsrc.MaxConcurrentStreams(uint32(o["maxConcurrentStreams"])),grpcsrc.ConnectionTimeout(time.Duration(o["connectionTimeoutMs"])*time.Millisecond),grpcsrc.UnaryInterceptor(unary),grpcsrc.StreamInterceptor(streams),grpcsrc.KeepaliveParams(keepalive.ServerParameters{MaxConnectionIdle:time.Duration(o["idleTimeoutMs"])*time.Millisecond,MaxConnectionAge:time.Duration(o["maxConnectionAgeMs"])*time.Millisecond,MaxConnectionAgeGrace:time.Duration(o["requestTimeoutMs"])*time.Millisecond}))
 for _,service:=range s.services{
 desc:=grpcsrc.ServiceDesc{ServiceName:string(service.FullName()),HandlerType:(*interface{})(nil),Metadata:service.ParentFile().Path()}
 for i:=0;i<service.Methods().Len();i++{m:=service.Methods().Get(i);if m.IsStreamingClient()||m.IsStreamingServer(){srv.Stop();return nil,fmt.Errorf("streaming application RPC is unsupported: %s",m.FullName())};path:="/"+string(service.FullName())+"/"+string(m.Name())
 desc.Methods=append(desc.Methods,grpcsrc.MethodDesc{MethodName:string(m.Name()),Handler:func(_ any,ctx context.Context,decode func(any)error,interceptor grpcsrc.UnaryServerInterceptor)(any,error){
 input:=dynamicpb.NewMessage(m.Input());if e:=decode(input);e!=nil{return nil,e}
 handler:=func(ctx context.Context,_ any)(any,error){return dispatch(ctx,s,m,path,input,o,callback)}
 if interceptor==nil{return handler(ctx,input)};return interceptor(ctx,input,&grpcsrc.UnaryServerInfo{FullMethod:path},handler)
 }})}
 srv.RegisterService(&desc,struct{}{})
 }
 if reflectionEnabled{ro:=reflection.ServerOptions{Services:srv,DescriptorResolver:s.files,ExtensionResolver:s.types};rv1.RegisterServerReflectionServer(srv,reflection.NewServerV1(ro));ralpha.RegisterServerReflectionServer(srv,reflection.NewServer(ro))}
 return srv,nil
}
func dispatch(ctx context.Context,s *schema,m protoreflect.MethodDescriptor,path string,input *dynamicpb.Message,o map[string]int,callback func(context.Context,*rpcCall)error)(any,error){
 b,e:=(protojson.MarshalOptions{Resolver:s.types,UseProtoNames:true}).Marshal(input);if e!=nil{return nil,status.Error(codes.Internal,"request serialization failed")}
 call:=&rpcCall{method:path,input:string(b),ctx:ctx}
 e=callback(ctx,call)
 if ctx.Err()!=nil{return nil,status.FromContextError(ctx.Err()).Err()}
 if e!=nil{return nil,status.Error(codes.Internal,"script callback failed")}
 if call.err!=nil{return nil,call.err};if !call.answered{return nil,status.Error(codes.Internal,"callback did not respond")}
 if len(call.output)>o["maxSendBytes"]*6{return nil,status.Error(codes.ResourceExhausted,"response JSON too large")}
 output:=dynamicpb.NewMessage(m.Output());if e=(protojson.UnmarshalOptions{Resolver:s.types}).Unmarshal([]byte(call.output),output);e!=nil{return nil,status.Error(codes.Internal,"invalid response JSON")}
 if proto.Size(output)>o["maxSendBytes"]{return nil,status.Error(codes.ResourceExhausted,"response too large")};return output,nil
}
''')
p=root/'zy.go';s=p.read_text(encoding='utf-8').replace('"github.com/php-any/origami/std/net/tcp"','"github.com/php-any/origami/std/net/tcp"\n "github.com/php-any/origami/std/net/grpc"').replace('tcp.Load(vm)','tcp.Load(vm)\n grpc.Load(vm)');p.write_text(s,encoding='utf-8')
