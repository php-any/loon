<?php

namespace Fixtures\Http;

#[\Database\Annotation\Table('loom_isolation_records')]
final class IsolationRecord extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    public int $id;
    public string $token;
}
