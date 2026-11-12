<?php

declare(strict_types=1);

#[\Container\Annotation\Singleton('di.module.shared')]
class DiModuleSingleton {}
#[\Container\Annotation\Scoped('di.module.scoped')]
class DiModuleScoped {}
#[\Container\Annotation\Component('di.module.fresh')]
class DiModuleTransient {}
