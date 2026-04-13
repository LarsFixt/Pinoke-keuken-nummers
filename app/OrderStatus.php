<?php

namespace App;

enum OrderStatus: string
{
    case Ready = 'ready';
    case Completed = 'completed';
}
