<?php

namespace App\CoreBundle\Model\Chat;

enum ChatOptionType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
