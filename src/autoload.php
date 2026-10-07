<?php

declare(strict_types=1);

// PSR-4 autoloader for VuFindConfigGui\ → src/. No Composer needed at runtime.
spl_autoload_register(static function (string $class): void {
    $prefix = 'VuFindConfigGui\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
