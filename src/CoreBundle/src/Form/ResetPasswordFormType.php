<?php

namespace App\CoreBundle\Form;

use App\CoreBundle\Validator\NewPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * New password chosen from a reset link, typed twice. Same rules as on the profile page (NewPassword).
 */
class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'The two passwords do not match.',
            'constraints' => [new NewPassword()],
        ]);
    }
}
