<?php

namespace App\CoreBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', EmailType::class, [
                'attr' => ['class' => 'form-control-lg', 'autocomplete' => 'email', 'placeholder' => 'Email address'],
                'row_attr' => ['class' => 'form-floating mb-2'],
                'label' => 'Email address',
                'mapped' => false,
            ])
            ->add('_password', PasswordType::class, [
                'attr' => ['class' => 'form-control-lg', 'autocomplete' => 'current-password', 'placeholder' => 'Password'],
                'row_attr' => ['class' => 'form-floating mb-2'],
                'toggle' => true,
                'use_toggle_form_theme' => false,
                'mapped' => false,
            ])
            ->add('_remember_me', CheckboxType::class, [
                'data' => true,
                'mapped' => false,
            ])
            ->add('submit', SubmitType::class, [
                'attr' => ['class' => 'btn-primary btn-lg w-100'],
                'label' => 'Login',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
        ]);
    }
}
