<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoEnvironmentReads;
use Cosmira\Soda\Rules\Architecture\NoRuntimeHooks;

$rules = RuleCatalog::standard();

foreach ($rules as $rule) {
    if ($rule instanceof NoEnvironmentReads) {
        $rule->exclude([__DIR__.'/src/Laravel/config/conform.php']);
    }

    if ($rule instanceof NoRuntimeHooks) {
        $rule->exclude([__DIR__.'/src/Validation/Pattern.php']);
    }
}

return Soda::configure()->withPaths([__DIR__.'/src'])->with($rules);
