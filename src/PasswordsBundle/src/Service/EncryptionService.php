<?php

namespace App\PasswordsBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class EncryptionService
{

    public function __construct(
        #[Autowire('%passwords.encryption_key%')] private string $encryptionKey
    )
    {
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($plainText, 'aes-256-cbc', $this->encryptionKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $cipherText): string
    {
        $cipherData = base64_decode($cipherText);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($cipherData, 0, $ivLength);
        $encryptedText = substr($cipherData, $ivLength);
        return openssl_decrypt($encryptedText, 'aes-256-cbc', $this->encryptionKey, 0, $iv);
    }

}