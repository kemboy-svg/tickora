<?php

namespace App\Enums;

enum TicketTypeStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Hidden = 'hidden';
}
