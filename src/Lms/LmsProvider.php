<?php
/**
 * Contract for a learning platform.
 *
 * The brief requires modules and lessons to be selectable from an
 * automatically populated list, but does not say which platform provides them.
 * Everything above this interface is written against these four methods, so
 * supporting a different platform means adding one class, not revisiting the
 * blocks, the admin screen or the caching layer.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

defined('ABSPATH') || exit;

interface LmsProvider
{
    /**
     * Every module available to the site.
     *
     * @return array<int,array{id:string,title:string,description:string,lesson_count:int,duration:int,url:string,updated_at:string}>
     *
     * @throws LmsUnavailable When the platform cannot be reached or answers badly.
     */
    public function modules(): array;

    /**
     * Lessons inside one module.
     *
     * @return array<int,array{id:string,title:string,summary:string,duration:int,url:string}>
     *
     * @throws LmsUnavailable
     */
    public function lessons(string $moduleId): array;

    /**
     * Human readable name, shown on the settings screen.
     */
    public function label(): string;

    /**
     * Checks credentials and reachability for the settings screen.
     *
     * @return array{ok:bool,message:string}
     */
    public function testConnection(): array;
}
