<?php

namespace App\PasswordsBundle\Controller;

use App\CoreBundle\Model\StandardTable\TableConfiguration;
use App\CoreBundle\Model\StandardTable\TableParameters;
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
    public function apiCredentialsTable(
        CredentialRepository              $credentialRepo,
        #[MapQueryString] TableParameters $params
    ): Response {
        $config = new TableConfiguration(
            rootEntityClass: Credential::class,
            rootAlias: 'e',
            fieldMappings: [
                'id'   => 'e.id',
                'name' => 'e.name',
                'tags' => 'e.tags',
            ],
            hydrateObjects: true,
            rowTransformer: function (array $row, Credential $entity): array {
                $row['type'] = $entity->getType();
                return $row;
            }
        );

        return $this->json([
            'rows'  => $credentialRepo->getTableRows($params, $config),
            'count' => $credentialRepo->getTableCount($params, $config),
            'total_count' => $credentialRepo->getTableUnfilteredCount($config),
        ]);
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
