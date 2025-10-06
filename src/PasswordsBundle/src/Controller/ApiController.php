<?php

namespace App\PasswordsBundle\Controller;

use App\CoreBundle\Entity\Table;
use App\CoreBundle\Entity\TableParameters;
use App\PasswordsBundle\Entity\Credential;
use App\PasswordsBundle\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[IsGranted('ROLE_USER_PASSWORDS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    #[Route('/credentials/table', name: 'credentials_table', methods: ['GET'])]
    public function apiCredentialsTable(CredentialRepository $credentialRepo, #[MapQueryString] TableParameters $params): Response
    {
        $table = new Table($credentialRepo, $params);
        $table->getDefaultParameters()
            ->setSorterField('name')
            ->setSorterOrder('ascend');
        $table->addColumn('ID', 'id')
            ->setHidden(true)
            ->setFixedLeft();
        $table->addColumn('Name', 'name')
            ->setSorter(true)
            ->setSortDirections(['ascend', 'descend']);
        $table->addColumn('Tags', 'tags')
            ->setValueFormat("tags");
        $table->addColumn('Type', 'type')
            ->setHidden(true);
        return $this->json($table);
    }

    #[IsGranted('ROLE_ADMIN_PASSWORDS')]
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

    #[IsGranted('ROLE_ADMIN_PASSWORDS')]
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

    #[IsGranted('ROLE_ADMIN_PASSWORDS')]
    #[Route('/credentials/{credential}', name: 'credentials_id_delete', requirements: ['credential' => '\d+'], methods: ['DELETE'])]
    public function apiCredentialsIdDelete(#[MapEntity(message: "Credential not found.")] Credential $credential): Response
    {
        $id = $credential->getId();
        $this->entityManager->remove($credential);
        $this->entityManager->flush();
        return $this->json(['id' => $id]);
    }

}