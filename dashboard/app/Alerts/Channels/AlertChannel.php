<?php

namespace App\Alerts\Channels;

use App\Models\Alert;

/**
 * One delivery target for an alert event (spec §9.2).
 *
 * The rule engine and the notifier only know this interface, so adding a
 * webhook or Slack channel later does not change alerting semantics. The
 * in-app channel is the `alerts` row itself and has no implementation here.
 */
interface AlertChannel
{
    /**
     * The `alert_deliveries.channel` value for this channel.
     */
    public function name(): string;

    /**
     * Whether the channel is switched on and configured well enough to be
     * given a delivery row. A disabled or unconfigured channel is skipped
     * without a row and without an HTTP or mail attempt.
     */
    public function enabled(): bool;

    /**
     * Deliver one event. Must never throw: transport problems are reported
     * as a failed {@see DeliveryResult} with a redacted error so one channel
     * cannot stop another.
     *
     * @param  string  $event  `opened` or `resolved`
     */
    public function send(Alert $alert, string $event): DeliveryResult;
}
