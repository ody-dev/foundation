<?php
/*
 * This file is part of ODY framework.
 *
 * @link     https://ody.dev
 * @document https://ody.dev/docs
 * @license  https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

namespace Ody\Foundation\Middleware\Attributes;

use Attribute;

/**
 * Middleware Group Attribute
 *
 * Use this attribute to apply a predefined middleware group to controllers and methods
 *
 * @Attribute
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class MiddlewareGroup
{
    /**
     * @var string The name of the middleware group
     */
    protected string $groupName;

    /**
     * Constructor
     *
     * @param string $groupName The name of the middleware group
     */
    public function __construct(string $groupName)
    {
        $this->groupName = $groupName;
    }

    /**
     * Get the middleware group name
     *
     * @return string
     */
    public function getGroupName(): string
    {
        return $this->groupName;
    }
}