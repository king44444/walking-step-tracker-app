<?php
declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $k, $def = null) {
        $v = getenv($k);
        return ($v === false || $v === '') ? $def : $v;
    }
}
