<?php

namespace App\Enums;

enum LateArrivalPolicy: string
{
    case Minimum = 'minimum';
    case Front = 'front';
    case Back = 'back';
}
