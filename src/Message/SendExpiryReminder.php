<?php
namespace App\Message;
#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class SendExpiryReminder { public function __construct(public string $deliveryId) {} }
