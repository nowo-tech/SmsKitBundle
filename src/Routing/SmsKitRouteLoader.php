<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Routing;

use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;

/**
 * Loads the send-test route (`type: nowo_sms_kit`) when the action is enabled.
 *
 * The route is declared with #[Route] on {@see SendTestSmsController} (name, methods); this loader reads the attribute
 * and mounts it at `test_action.path`, so the path stays configurable while the definition lives on the controller
 * (REQ-SF-004). The controller is not autoconfigured, so `routing.controllers` never mounts it (rule 7).
 */
final class SmsKitRouteLoader extends Loader
{
    /** Routing resource type. */
    public const TYPE = 'nowo_sms_kit';

    private bool $loaded = false;

    /**
     * @param bool $enabled `test_action.enabled`
     * @param string $path `test_action.path`
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $path,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the route collection (once per router warm-up).
     *
     * @param mixed $resource ignored
     * @param string|null $type `nowo_sms_kit`
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_sms_kit" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $collection = new RouteCollection();
        if (!$this->enabled) {
            return $collection;
        }

        $method = (new ReflectionClass(SendTestSmsController::class))->getMethod('__invoke');
        foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
            $declared = $attribute->newInstance();
            $suffix   = is_string($declared->path) ? $declared->path : '';
            $collection->add((string) $declared->name, new Route(
                rtrim($this->path, '/') . $suffix,
                ['_controller' => SendTestSmsController::class],
                methods: $declared->methods,
            ));
        }

        return $collection;
    }

    /**
     * Supports the `nowo_sms_kit` type.
     *
     * @param mixed $resource ignored
     * @param string|null $type resource type
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
