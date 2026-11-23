<?php

namespace App\CoreBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Forgot password" form: the email of the account to send the reset link to.
 */
class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'constraints' => [
                new Assert\NotBlank(message: 'Enter your email address.'),
                new Assert\Email(),
            ],
        ]);
    }
}
