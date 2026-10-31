<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms as SymfonyForms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * A FormKit CSRF-only form factory on a real form factory with the CSRF and HttpFoundation extensions.
 */
final class Forms
{
    public static function csrf(?CsrfTokenManagerInterface $tokens = null): CsrfOnlyFormFactory
    {
        $tokens ??= self::tokens();

        $merger = new FormOptionsMerger(['default' => ['translation_domain' => 'messages', 'defaults' => ['attr' => [], 'row_attr' => []], 'field_types' => [], 'by_form' => []]], 'default', new ConstraintDefinitionFactory());

        return new CsrfOnlyFormFactory(SymfonyForms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([new CsrfOnlyType($merger, new FormTypeMap())], []))
            ->addExtension(new CsrfExtension($tokens))
            ->addExtension(new HttpFoundationExtension())
            ->getFormFactory());
    }

    public static function tokens(?TokenStorageInterface $storage = null): CsrfTokenManager
    {
        return new CsrfTokenManager(storage: $storage ?? new InMemoryCsrfTokenStorage());
    }
}
