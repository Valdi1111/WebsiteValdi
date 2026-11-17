<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Entity\User;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[ResolveEntityFromRoute(setter: 'setAccount')]
#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[Route('/api/accounts/{account}', name: 'api_gryphline_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private GryphlineAccount $account;

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameBiz
    ) {
    }

    protected function getAccount(): GryphlineAccount
    {
        return $this->account;
    }

    public function setAccount(#[MapEntity(id: 'account', message: "Gryphline Account not found.")] GryphlineAccount $account): void
    {
        $this->account = $account;
    }

    protected function checkAccountOwnership(User $user): void
    {
        if ($this->getAccount()->getUser() !== $user) {
            throw $this->createAccessDeniedException("You do not own this Gryphline account.");
        }
    }

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[CurrentUser] User $user): Response
    {
        $this->checkAccountOwnership($user);

        return $this->json($this->getAccount()->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $denormalizer->denormalize($req->getPayload()->all(), GryphlineGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/sync', name: 'pulls_sync', requirements: ['gameProfile' => '\d+'], methods: ['POST'])]
    public function apiGameProfilePullsSync(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        MessageBusInterface $bus
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support automated pull synchronization.");
        }

        $delaySeconds = 0;
        foreach ($gameService->getPullPoolCategories() as $category) {
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $bus->dispatch(
                new SyncPullsPageProfileMessage($gameProfile->getId(), $category, null),
                $stamps
            );
            $delaySeconds += 15;
        }

        return $this->json([
            'success' => true,
            'message' => 'Pulls synchronization queued in background.',
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls/overview', name: 'pulls_overview', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsOverview(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $bannerGroups = $gameService->getGachaBannerGroups();
        $rarityConfig = $gameService->getRarityConfig();
        $overview = $pullRepo->buildOverview($gameProfile, $bannerGroups, $rarityConfig);

        return $this->json([
            'profile' => $gameProfile,
            'rarity_config' => $rarityConfig,
            'total_pulls' => $overview['total_pulls'],
            'banners' => $overview['banners'],
            'monthly_timeline' => $overview['monthly_timeline'],
        ]);
    }

    #[Route('/gameProfiles/{gameProfile}/pulls', name: 'pulls_list', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilePullsList(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        GryphlinePullRepository $pullRepo
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            throw new BadRequestHttpException("Unsupported game service.");
        }

        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());
        if (!$gameService instanceof HasAutoPullImportInterface) {
            throw new BadRequestHttpException("This game profile does not support pull tracking.");
        }

        $bannerGroups = $gameService->getGachaBannerGroups();
        $rarityConfig = $gameService->getRarityConfig();

        $bannerKey = $req->query->getString('banner', $req->query->getString('category'));
        if ($bannerKey === '' || !isset($bannerGroups[$bannerKey])) {
            $firstKey = array_key_first($bannerGroups);
            $bannerGroup = $firstKey !== null ? $bannerGroups[$firstKey] : [
                'key' => 'char',
                'name' => 'Character Headhunting',
                'categories' => ['char'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ];
        } else {
            $bannerGroup = $bannerGroups[$bannerKey];
        }

        $raritiesParam = $req->query->getString('rarities');
        $rarities = [];
        if ($raritiesParam !== '') {
            foreach (explode(',', $raritiesParam) as $r) {
                $rInt = (int) trim($r);
                if ($rInt > 0) {
                    $rarities[] = $rInt;
                }
            }
        } elseif ($req->query->has('rarity') && $req->query->getInt('rarity') > 0) {
            $rarities[] = $req->query->getInt('rarity');
        }

        $search = $req->query->getString('search');
        $page = max(1, $req->query->getInt('page', 1));
        $limit = min(100, max(5, $req->query->getInt('limit', 15)));

        $result = $pullRepo->findBannerDetailWithPity(
            $gameProfile,
            $bannerGroup,
            $rarityConfig,
            $rarities,
            $search ?: null,
            $page,
            $limit
        );

        return $this->json([
            'banner' => $result['banner'],
            'rarity_config' => $rarityConfig,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}
