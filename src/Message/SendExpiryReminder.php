<?php
namespace App\Message;
final readonly class SendExpiryReminder { public function __construct(public string $deliveryId) {} }
