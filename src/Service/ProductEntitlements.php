<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{License, LicensePlan, Product};

final class ProductEntitlements
{
    public static function validateDefinition(Product $product): void
    {
        foreach (array_merge($product->getAllowedFeatures(), $product->getRequiredFeatures()) as $feature) {
            if (!is_string($feature) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}$/D', $feature)) { throw new \DomainException('entitlements.invalid_definition'); }
        }
        if (array_diff($product->getRequiredFeatures(), $product->getAllowedFeatures()) !== []) { throw new \DomainException('entitlements.invalid_definition'); }
        foreach ($product->getFeatureQuotas() as $feature => $limit) {
            if (!in_array($feature, $product->getAllowedFeatures(), true) || !is_int($limit) || $limit < 1 || $limit > 1000000000) { throw new \DomainException('entitlements.invalid_definition'); }
        }
    }

    public function rules(Product $product): array
    {
        self::validateDefinition($product);
        return ['version' => 1, 'allowedFeatures' => $product->getAllowedFeatures(), 'requiredFeatures' => $product->getRequiredFeatures(), 'maxInstallations' => $product->getMaxInstallations(), 'featureQuotas' => $product->getFeatureQuotas()];
    }

    public function assertRights(Product $product, array $features, int $installations, array $quotas): void
    {
        $this->assertAgainst($this->rules($product), $features, $installations, $quotas);
    }

    public function assertLicense(License $license): void
    {
        if (!$license->getProduct()) { throw new \DomainException('entitlements.invalid_definition'); }
        $snapshot = $license->getEntitlementSnapshot();
        if ($snapshot !== null && isset($snapshot['grantedFeatures']) && ($snapshot['grantedFeatures'] !== $license->getFeatures() || $snapshot['grantedQuotas'] !== $license->getQuotas() || $snapshot['grantedInstallations'] !== $license->getMaxDomains())) { $snapshot = null; }
        $this->assertAgainst($snapshot ?? $this->rules($license->getProduct()), $license->getFeatures(), $license->getMaxDomains(), $license->getQuotas());
    }

    public function capture(License $license): void
    {
        $this->assertRights($license->getProduct(), $license->getFeatures(), $license->getMaxDomains(), $license->getQuotas());
        $license->setEntitlementSnapshot($this->rules($license->getProduct()) + ['grantedFeatures' => $license->getFeatures(), 'grantedQuotas' => $license->getQuotas(), 'grantedInstallations' => $license->getMaxDomains()]);
    }

    public function applyPlan(License $license, LicensePlan $plan, ?\DateTimeImmutable $now = null): void
    {
        if (!$plan->isActive() || $plan->getDurationDays() < 1 || $plan->getDurationDays() > 36500 || ($license->getProduct() && (string) $license->getProduct()->getId() !== (string) $plan->getProduct()->getId())) { throw new \DomainException('entitlements.invalid_plan'); }
        $this->assertRights($plan->getProduct(), $plan->getFeatures(), $plan->getMaxDomains(), $plan->getQuotas());
        $expiry = ($now ?? new \DateTimeImmutable())->modify('+'.$plan->getDurationDays().' days');
        $license->setProduct($plan->getProduct())->setFeatures($plan->getFeatures())->setMaxDomains($plan->getMaxDomains())->setQuotas($plan->getQuotas())->setExpiresAt($expiry)->setUpdatesAllowed($plan->getUpdatesAllowed())->setUpdatesUntil($plan->getUpdatesAllowed() === true ? $expiry : null);
        $license->setPlanSnapshot(['version' => 1, 'id' => (string) $plan->getId(), 'name' => $plan->getName(), 'durationDays' => $plan->getDurationDays(), 'features' => $plan->getFeatures(), 'maxDomains' => $plan->getMaxDomains(), 'quotas' => $plan->getQuotas(), 'updatesAllowed' => $plan->getUpdatesAllowed()]);
        $this->capture($license);
    }

    private function assertAgainst(array $rules, array $features, int $installations, array $quotas): void
    {
        foreach ($features as $feature) { if (!is_string($feature) || !in_array($feature, $rules['allowedFeatures'], true)) { throw new \DomainException('entitlements.invalid_features'); } }
        if (array_diff($rules['requiredFeatures'], $features) !== [] || $installations < 1 || $installations > $rules['maxInstallations']) { throw new \DomainException('entitlements.invalid_features'); }
        foreach ($rules['featureQuotas'] as $feature => $limit) { if (in_array($feature, $features, true) && !array_key_exists($feature, $quotas)) { throw new \DomainException('entitlements.invalid_quotas'); } }
        foreach ($quotas as $feature => $limit) {
            if (!in_array($feature, $features, true) || !isset($rules['featureQuotas'][$feature]) || !is_int($limit) || $limit < 1 || $limit > $rules['featureQuotas'][$feature]) { throw new \DomainException('entitlements.invalid_quotas'); }
        }
    }
}
