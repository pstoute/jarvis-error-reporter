<?php

namespace StouteWebSolutions\JarvisErrorReporter\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void capture(\Throwable $e, array $extraContext = [])
 * @method static self setContext(array $context)
 * @method static self setUser(?int $id, ?string $email = null, ?string $name = null)
 *
 * @see \StouteWebSolutions\JarvisErrorReporter\JarvisErrorReporter
 */
class Jarvis extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \StouteWebSolutions\JarvisErrorReporter\JarvisErrorReporter::class;
    }
}
