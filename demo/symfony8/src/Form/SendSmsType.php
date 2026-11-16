<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demo form: destination + body, sent through SmsSenderInterface (FormKit type, profile `demo`, CSRF on; REQ-DEMO-015).
 */
#[FormKitConfig('demo')]
final class SendSmsType extends FormKitAbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'to', ['label' => 'Destination (E.164)', 'constraints' => [new NotBlank()]]);
        $this->addTextarea($builder, 'body', ['label' => 'Message', 'constraints' => [new NotBlank(), new Length(max: 1600)], 'attr' => ['rows' => 3]]);
        $this->addField($builder, 'submit', 'submit', ['label' => 'Send', 'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'demo_send_sms']);
    }
}
