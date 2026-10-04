<?php

declare(strict_types=1);

namespace App\Form\Extension;

use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Translation\TranslatableMessage;

final class FieldHelpExtension extends AbstractTypeExtension
{
    private const array FIELDS = [
        'company' => 'company', 'firstname' => 'firstname', 'lastname' => 'lastname',
        'email' => 'email', 'street' => 'street', 'zip' => 'postal_code', 'postalCode' => 'postal_code',
        'city' => 'city', 'country' => 'country', 'mobile' => 'mobile', 'phone' => 'phone', 'vatId' => 'vat_id',
        'logoImage' => 'image', 'profileImage' => 'image', 'active' => 'active', 'roles' => 'roles',
        'globalAccess' => 'global_access', 'customers' => 'customers',
        'reminderLocale' => 'reminder_locale', 'reminderRecipients' => 'reminder_recipients',
        'slug' => 'slug', 'name' => 'name', 'description' => 'description', 'currentVersion' => 'version',
        'customer' => 'customer', 'product' => 'product', 'plan' => 'plan', 'licenseKey' => 'license_key',
        'mode' => 'mode', 'durationDays' => 'duration', 'maxDomains' => 'installation_limit',
        'maxInstallations' => 'installation_limit', 'features' => 'features', 'allowedFeatures' => 'allowed_features',
        'requiredFeatures' => 'required_features', 'quotas' => 'quotas', 'featureQuotas' => 'quotas',
        'feature' => 'feature', 'limit' => 'quota_limit', 'updatesAllowed' => 'updates',
        'expiresAt' => 'expiry', 'expiry' => 'expiry', 'notes' => 'notes',
        'tokenLifetimeSeconds' => 'token_lifetime', 'gracePeriodSeconds' => 'grace_period',
        'tenant' => 'tenant', 'domain' => 'domain', 'installation' => 'installation',
        'currentPassword' => 'current_password', 'password' => 'current_password',
        'newPassword' => 'new_password', 'plainPassword' => 'new_password',
        'code' => 'auth_code', 'proof' => 'auth_proof', 'newCode' => 'new_auth_code',
        'reason' => 'reason', 'scopes' => 'scopes', 'url' => 'webhook_url', 'events' => 'webhook_events',
    ];

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if ($form->getConfig()->getType()->getInnerType() instanceof HiddenType
            || ($form->isRoot() && $options['compound'])
            || $view->vars['help'] !== null) {
            return;
        }

        $name = $form->getName();
        $parentName = $form->getParent()?->getName();
        $help = match (true) {
            $name === 'second' && \in_array($parentName, ['newPassword', 'plainPassword'], true) => 'repeat_password',
            $name === 'first' && \in_array($parentName, ['newPassword', 'plainPassword'], true) => 'new_password',
            $options['label'] === 'license_action.expires_at' => 'renewal_expiry',
            $options['label'] === 'two_factor.recovery_code' => 'recovery_code',
            isset(self::FIELDS[$name]) => self::FIELDS[$name],
            \in_array($parentName, ['features', 'allowedFeatures', 'requiredFeatures'], true) => 'feature',
            $parentName === 'reminderRecipients' => 'email',
            default => $this->typeHelp($form),
        };

        $view->vars['help'] = new TranslatableMessage('form_help.'.$help, domain: 'messages');
        // EasyAdmin also uses field metadata to render help in its own themes.
        $field = $form->getConfig()->getAttribute('ea_field');
        if ($field instanceof FieldDto && $field->getHelp() === null) {
            $field->setHelp($view->vars['help']);
        }
    }

    private function typeHelp(FormInterface $form): string
    {
        for ($type = $form->getConfig()->getType(); $type !== null; $type = $type->getParent()) {
            if ($type->getInnerType() instanceof CheckboxType) {
                return 'checkbox';
            }
            if ($type->getInnerType() instanceof ChoiceType) {
                return 'choice';
            }
        }

        return 'value';
    }
}
