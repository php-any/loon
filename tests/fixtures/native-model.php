<?php

namespace NativeModelRegression;

#[\Database\Annotation\Table('native_model_probe')]
final class Entity extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('probe_id', false)]
    public ?int $id = null;
    #[\Database\Annotation\Column('display_name', false)]
    public string $name;
    #[\Database\Annotation\Column('score', false)]
    public float $score;
    #[\Database\Annotation\Column('enabled', false)]
    public bool $enabled;
    #[\Database\Annotation\Column('note', true)]
    public ?string $note = null;
    public function __construct(string $name, float $score, bool $enabled)
    {
        $this->name = $name;
        $this->score = $score;
        $this->enabled = $enabled;
    }
    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
