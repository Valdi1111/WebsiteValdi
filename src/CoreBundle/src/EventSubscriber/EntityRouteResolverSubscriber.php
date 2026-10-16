<?php

namespace App\CoreBundle\EventSubscriber;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsEventListener(priority: 10)]
class EntityRouteResolverSubscriber
{
    public function __construct(
        #[AutowireLocator('controller.argument_value_resolver', indexAttribute: 'name')]
        private ContainerInterface $resolverLocator
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        // Only process main/master requests; skip sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $rawController = $event->getController();

        // Safe controller instance extraction mimicking Symfony's ControllerEvent::evaluate()
        $controller = match (true) {
            \is_object($rawController) && !$rawController instanceof \Closure => $rawController,
            \is_array($rawController) && isset($rawController[0]) && \is_object($rawController[0]) => $rawController[0],
            default => null,
        };

        // If the controller is not an instantiated object (e.g. static callback or raw Closure), bail out
        if ($controller === null) {
            return;
        }

        $refClass = new \ReflectionClass($controller);
        $request = $event->getRequest();

        // Retrieve all #[ResolveEntityFromRoute] attributes declared on the controller class
        $classAttributes = $refClass->getAttributes(ResolveEntityFromRoute::class);
        if (empty($classAttributes)) {
            return;
        }

        foreach ($classAttributes as $classAttribute) {
            /** @var ResolveEntityFromRoute $config */
            $config = $classAttribute->newInstance();
            $setterName = $config->setter;

            // 1. Verify that the configured setter method actually exists on the class
            if (!$refClass->hasMethod($setterName)) {
                throw new \BadMethodCallException(sprintf(
                    'Configured setter "%s::%s()" in #[ResolveEntityFromRoute] does not exist.',
                    $refClass->getName(),
                    $setterName
                ));
            }

            $method = $refClass->getMethod($setterName);

            // 2. Validate that the setter method expects exactly 1 mandatory argument
            if ($method->getNumberOfRequiredParameters() !== 1) {
                throw new \LogicException(sprintf(
                    'Setter "%s::%s()" must require exactly 1 argument (%d required given).',
                    $refClass->getName(),
                    $setterName,
                    $method->getNumberOfRequiredParameters()
                ));
            }

            $param = $method->getParameters()[0];
            $paramType = $param->getType();

            // 3. Ensure the parameter has a valid class-based entity type hint
            if (!$paramType instanceof \ReflectionNamedType || $paramType->isBuiltin()) {
                throw new \LogicException(sprintf(
                    'Parameter "$%s" in "%s::%s()" must have a class entity type-hint.',
                    $param->getName(),
                    $refClass->getName(),
                    $setterName
                ));
            }

            // 4. Ensure the parameter is decorated with Symfony\'s native #[MapEntity] attribute
            $mapEntityAttrs = $param->getAttributes(MapEntity::class);
            if (empty($mapEntityAttrs)) {
                throw new \LogicException(sprintf(
                    'Parameter "$%s" in setter "%s::%s()" must be annotated with #[MapEntity].',
                    $param->getName(),
                    $refClass->getName(),
                    $setterName
                ));
            }

            /** @var MapEntity $mapEntity */
            $mapEntity = $mapEntityAttrs[0]->newInstance();

            // 5. Construct a synthetic ArgumentMetadata object representing the setter argument
            $argumentMetadata = new ArgumentMetadata(
                name: $param->getName(),
                type: $paramType->getName(),
                isVariadic: false,
                hasDefaultValue: $param->isDefaultValueAvailable(),
                defaultValue: $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                isNullable: $paramType->allowsNull(),
                attributes: [$mapEntity]
            );

            // 6. Determine the resolver name
            if (!$this->resolverLocator->has($mapEntity->resolver)) {
                throw new \RuntimeException(sprintf(
                    'Value resolver "%s" not found in locator.',
                    $mapEntity->resolver
                ));
            }

            $resolver = $this->resolverLocator->get($mapEntity->resolver);
            $resolved = $resolver->resolve($request, $argumentMetadata);

            if (empty($resolved)) {
                if (!$paramType->allowsNull()) {
                    throw new NotFoundHttpException(sprintf(
                        'Could not resolve entity for parameter "$%s" in "%s::%s()".',
                        $param->getName(),
                        $refClass->getName(),
                        $setterName
                    ));
                }
                $entity = null;
            } else {
                $entity = $resolved[0];
            }

            // 7. Invoke the setter on the controller instance via reflection
            $method->invoke($controller, $entity);
        }
    }
}
