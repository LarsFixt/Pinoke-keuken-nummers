<?php

namespace App;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Completed = 'completed';
}
