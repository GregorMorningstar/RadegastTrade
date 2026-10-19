<?php

namespace App\Enums;

/**
 * What a person may do for a unit besides managing it. Only placing orders for now; confirming orders of the seller is
 * left to the managers and the owner.
 */
enum OrderAbility: string
{
    case PlaceOrders = 'place_orders';
}
