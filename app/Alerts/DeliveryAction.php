<?php

namespace App\Alerts;

/**
 * What the delivery job should do with a row it was asked to deliver.
 *
 * - {@see Deliver}: run the channel send.
 * - {@see Skip}: the row is not owed right now, leave it untouched.
 * - {@see RestoreSent}: the reminder was queued before an acknowledgement;
 *   put the row back to `sent` so it is not left looking pending.
 */
enum DeliveryAction
{
    case Deliver;
    case Skip;
    case RestoreSent;
}
