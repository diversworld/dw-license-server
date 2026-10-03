<?php
namespace App\Message;
#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class SendWebhook { public function __construct(public string $deliveryId) {} }
