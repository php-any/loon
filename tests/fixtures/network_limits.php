<?php
// 独立的标准库探针：特意不加载 Loom 及其配置。
$server=new Net\Http\Server('127.0.0.1',(int)$argv[1]);
$server->configure(['maxConnections'=>4,'maxRequests'=>1,'maxBodyBytes'=>8,'maxHeaderBytes'=>4096,'readHeaderTimeoutMs'=>200,'readTimeoutMs'=>300,'writeTimeoutMs'=>500,'requestTimeoutMs'=>400,'idleTimeoutMs'=>300]);
$server->any(function($request,$writer): void {
    if ($request->path()==='/ws') {
        $connection=Net\Websocket\upgrade($request,$writer,false);
        $connection->configure(['maxMessageBytes'=>16,'readTimeoutMs'=>100,'writeTimeoutMs'=>100]);
        try { $connection->writeText($connection->readText()); }
        catch(Throwable $error) { /* 此资源探针预期会被拒绝。 */ }
        finally { $connection->close(); }
        return;
    }
    $writer->write('ok');
});
$server->run();
