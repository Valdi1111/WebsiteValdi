<?php

namespace App\CoreBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ResolveEntityFromRoute
{
    public function __construct(
        public string $setter
    ) {
    }
}
