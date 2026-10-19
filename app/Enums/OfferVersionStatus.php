<?php

namespace App\Enums;

enum OfferVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
