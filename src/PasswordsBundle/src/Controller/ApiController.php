<?php

namespace App\PasswordsBundle\Controller;

use App\PasswordsBundle\Entity\Credential;
use App\PasswordsBundle\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[IsGranted('ROLE_USER_PASSWORDS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct(
        private readonly EncryptionService      $encryptionService,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    #[Route('/encrypt', name: 'encrypt', methods: ['GET'])]
    public function encrypt(#[MapQueryParameter] string $str): Response
    {
        return $this->json(['res' => $this->encryptionService->encrypt($str)]);
    }

    #[Route('/decrypt', name: 'decrypt', methods: ['GET'])]
    public function decrypt(#[MapQueryParameter] string $str): Response
    {
        return $this->json(['res' => $this->encryptionService->decrypt($str)]);
    }

    #[Route('/credentials', name: 'credentials_all', methods: ['GET'])]
    public function apiCredentialsAll(#[MapEntity(class: Credential::class, expr: 'repository.findBy({}, {"name": "ASC"})')] array $credentials): Response
    {
        return $this->json($credentials, 200, [], ['groups' => ['credential:list']]);
    }

    #[Route('/credentials', name: 'credentials_add', methods: ['POST'])]
    public function apiCredentialsAdd(Request $req, DenormalizerInterface $denormalizer): Response
    {
        $credential = $denormalizer->denormalize(
            $req->getPayload()->all(),
            Credential::class
        );
        $this->entityManager->persist($credential);
        $this->entityManager->flush();
        return $this->json($credential);
    }

    #[Route('/credentials/{credential}', name: 'credentials_id_get', requirements: ['credential' => '\d+'], methods: ['GET'])]
    public function apiCredentialsIdGet(#[MapEntity(message: "Credential not found.")] Credential $credential): Response
    {
        return $this->json($credential);
    }

    #[Route('/credentials/{credential}', name: 'credentials_id_edit', requirements: ['credential' => '\d+'], methods: ['PUT'])]
    public function apiCredentialsIdEdit(Request $req, #[MapEntity(message: "Credential not found.")] Credential $credential, DenormalizerInterface $denormalizer): Response
    {
        $denormalizer->denormalize(
            $req->getPayload()->all(),
            Credential::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $credential]
        );
        $this->entityManager->flush();
        return $this->json($credential);
    }

    #[Route('/credentials/{credential}', name: 'credentials_id_delete', requirements: ['credential' => '\d+'], methods: ['DELETE'])]
    public function apiCredentialsIdDelete(#[MapEntity(message: "Credential not found.")] Credential $credential): Response
    {
        $id = $credential->getId();
        $this->entityManager->remove($credential);
        $this->entityManager->flush();
        return $this->json(['id' => $id]);
    }

}