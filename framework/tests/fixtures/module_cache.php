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

#[\Loom\Attributes\Controller('', [new MutableMetadataMiddleware()])]
final class MutableMetadataEndpoint
{
    #[\Loom\Attributes\Route('GET', '/mutable')]
    public function index(): \Loom\Response
    {
        return new \Loom\Response('mutable');
    }
}
