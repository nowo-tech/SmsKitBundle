<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\AuthKit;

use Nowo\SmsKitBundle\Phone\E164;

use function is_callable;
use function is_string;
use function property_exists;
use function trim;
use function ucfirst;

/**
 * Default {@see PhoneNumberResolverInterface}: `get<Property>()`, `<property>()` or the public property of the user
 * (default `phone`), kept only when it is a valid E.164 number.
 */
final readonly class PropertyPhoneNumberResolver implements PhoneNumberResolverInterface
{
    /**
     * @param string $property property name (`nowo_sms_kit.auth_kit.password_reset.phone_property`)
     */
    public function __construct(
        private string $property = 'phone',
    ) {
    }

    /**
     * Phone of the user in E.164, or null.
     *
     * @param object $user resolved AuthKit account
     */
    public function resolve(object $user): ?string
    {
        $value = null;
        foreach (['get' . ucfirst($this->property), $this->property] as $method) {
            $callable = [$user, $method];
            if (is_callable($callable)) {
                $value = $callable();
                break;
            }
        }
        if ($value === null && property_exists($user, $this->property) && isset($user->{$this->property})) {
            $value = $user->{$this->property};
        }

        if (!is_string($value) || !E164::isValid($value)) {
            return null;
        }

        return E164::normalize(trim($value));
    }
}
