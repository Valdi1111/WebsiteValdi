<?php

namespace App\CoreBundle\ValueResolver;

use App\CoreBundle\Attribute\MapDiscordInteraction;
use App\CoreBundle\Exception\InvalidDiscordInteractionException;
use App\CoreBundle\Exception\InvalidDiscordSignatureException;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteraction;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * #[MapDiscordInteraction]: verifies the Ed25519 signature Discord puts on every interaction
 * (https://discord.com/developers/docs/interactions/overview#setting-up-an-endpoint-validating-security-request-headers),
 * then denormalizes the body into a DiscordInteraction.
 *
 * - DISCORD_PUBLIC_KEY empty: 404, the bot is disabled, before reading anything;
 * - signature missing, malformed or wrong: 401, which Discord requires (it also sends invalid signatures on purpose
 *   and stops calling an endpoint that accepts them). Malformed hex never reaches hex2bin() or sodium, which would warn
 *   or throw;
 * - signed but not an interaction: 400.
 *
 * No check on the age of the timestamp: Discord does not ask for one, and a replay would only run again a command of
 * the same user.
 */
#[AsTargetedValueResolver]
final class DiscordInteractionValueResolver implements ValueResolverInterface
{
    public const string SIGNATURE_HEADER = 'X-Signature-Ed25519';
    public const string TIMESTAMP_HEADER = 'X-Signature-Timestamp';

    public function __construct(
        private readonly SerializerInterface $serializer,
        #[Autowire(env: 'DISCORD_PUBLIC_KEY')]
        private readonly string $publicKey,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return iterable<DiscordInteraction>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== DiscordInteraction::class) {
            throw new \LogicException(sprintf('#[%s] maps a "%s" argument, "$%s" is a "%s".', MapDiscordInteraction::class, DiscordInteraction::class, $argument->getName(), $argument->getType() ?? 'mixed'));
        }

        if ($this->publicKey === '') {
            throw new NotFoundHttpException('The Discord bot is disabled.');
        }

        if (!$this->hasValidSignature($request)) {
            throw new InvalidDiscordSignatureException();
        }

        try {
            $interaction = $this->serializer->deserialize($request->getContent(), DiscordInteraction::class, 'json');
        } catch (SerializerExceptionInterface) {
            throw new InvalidDiscordInteractionException('Malformed Discord interaction.');
        }

        return [$interaction];
    }

    private function hasValidSignature(Request $request): bool
    {
        $publicKey = self::decodeHex($this->publicKey, \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($publicKey === null) {
            $this->logger?->error('DISCORD_PUBLIC_KEY is not the hex public key of the Discord application ({length} hex digits): every interaction is refused.', ['length' => 2 * \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES]);

            return false;
        }

        $signature = self::decodeHex((string) $request->headers->get(self::SIGNATURE_HEADER), \SODIUM_CRYPTO_SIGN_BYTES);
        $timestamp = (string) $request->headers->get(self::TIMESTAMP_HEADER);
        if ($signature === null || $timestamp === '') {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $timestamp.$request->getContent(), $publicKey);
    }

    /**
     * @return string|null the bytes, null unless exactly $bytes bytes of hex
     */
    private static function decodeHex(string $hex, int $bytes): ?string
    {
        // Length and digits first: hex2bin() warns on odd lengths and other characters, sodium throws on wrong lengths
        if (strlen($hex) !== 2 * $bytes || !ctype_xdigit($hex)) {
            return null;
        }

        $binary = hex2bin($hex);

        return $binary === false ? null : $binary;
    }
}
