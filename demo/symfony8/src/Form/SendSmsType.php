<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demo form: destination + body, sent through SmsSenderInterface (FormKit type, CSRF on).
 */
final class SendSmsType extends FormKitAbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('to', TextType::class, ['label' => 'Destination (E.164)', 'constraints' => [new NotBlank()]])
            ->add('body', TextareaType::class, ['label' => 'Message', 'constraints' => [new NotBlank(), new Length(max: 1600)], 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'demo_send_sms']);
    }
}
