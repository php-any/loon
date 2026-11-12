<?php

#[\Loom\Attributes\Module(['MetadataFixture' => 'components'])]
final class MetadataFixtureModule {}

final class MutableMetadataMiddleware implements \Loom\Middleware
{
    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        return $next($request);
    }
}

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(MutableMetadataMiddleware::class)]
final class MutableMetadataEndpoint
{
    #[\Net\Annotation\GetMapping('/mutable')]
    public function index(): \Loom\Response
    {
        return new \Loom\Response('mutable');
    }
}
