<?php

namespace App\Enums;

enum EventVisibility: string
{
    /** Listed in search, categories and the homepage. */
    case Public = 'public';

    /** Reachable by direct link only. */
    case Unlisted = 'unlisted';
}
