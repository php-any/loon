<?php

declare(strict_types=1);

#[Loom\Attributes\Service('di.module.shared', 'singleton')]
class DiModuleSingleton {}
#[Loom\Attributes\Service('di.module.scoped')]
class DiModuleScoped {}
#[Loom\Attributes\Service('di.module.fresh', 'transient')]
class DiModuleTransient {}
