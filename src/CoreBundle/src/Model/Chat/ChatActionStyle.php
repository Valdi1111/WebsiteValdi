<?php

namespace App\CoreBundle\Model\Chat;

enum ChatActionStyle: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Success = 'success';
    case Danger = 'danger';
}
