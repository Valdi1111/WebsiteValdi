<?php

namespace App\AnimeBundle\Entity;

enum Nsfw: string
{
    /**
     * This work is safe for work
     */
    case white = 'white';
    /**
     * This work may be not safe for work
     */
    case gray = 'gray';
    /**
     * This work is not safe for work
     */
    case black = 'black';
}
