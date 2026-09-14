<?php

namespace App\CoreBundle\Model;

interface LabeledInterface
{
    public function getLabel(): string;
}