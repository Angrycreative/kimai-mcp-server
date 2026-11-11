<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Controller;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\McpServerBundle;
use KimaiPlugin\McpServerBundle\Server\ClientInfo;
use KimaiPlugin\McpServerBundle\Server\ServerFactory;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Transport\StreamableHttpTransport;
use KimaiPlugin\McpServerBundle\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Message\ResponseInterface;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Message\ServerRequestInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The MCP endpoint. Kimai's API firewall has already authenticated the Bearer token by the time this runs; the
 * controller only translates between Symfony and the SDK's PSR-7 world.
 */
#[IsGranted('API')]
#[IsGranted('mcp_access')]
final class McpController extends AbstractController
{
    public function __construct(
        private readonly ServerFactory $serverFactory,
        private readonly ClientInfo $clientInfo,
    ) {
    }

    #[Route(path: '/mcp', name: 'kimai_mcp_server', methods: ['POST', 'GET', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        // Only API tokens. Kimai's API also accepts a browser session cookie, which would let any page the user has
        // open call this endpoint as them.
        if (!str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ')) {
            return new JsonResponse(['error' => 'The MCP endpoint only accepts Kimai API tokens (Authorization: Bearer).'], Response::HTTP_UNAUTHORIZED);
        }

        if (!McpServerBundle::isVendorInstalled()) {
            return new JsonResponse(['error' => 'The MCP server plugin is installed without its bundled libraries (vendor-prefixed/).'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $factory = new Psr17Factory();
        $transport = new StreamableHttpTransport(
            $this->toPsr($request, $factory),
            $factory,
            $factory,
            // No browser should call this cross-origin. The SDK's other default, a localhost-only Host check, is for
            // servers on a developer's machine; Kimai already validates the host against TRUSTED_HOSTS.
            middleware: [new CorsMiddleware()],
        );

        $client = $this->clientInfo->describe($request, (int) $user->getId());
        $response = $this->serverFactory->create($user, $client)->run($transport);

        return $this->fromPsr($response);
    }

    private function toPsr(Request $request, Psr17Factory $factory): ServerRequestInterface
    {
        $psr = $factory->createServerRequest($request->getMethod(), $request->getUri(), $request->server->all());

        foreach ($request->headers->all() as $name => $values) {
            $psr = $psr->withHeader($name, $values);
        }

        return $psr
            ->withBody($factory->createStream($request->getContent()))
            ->withQueryParams($request->query->all());
    }

    private function fromPsr(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        return new Response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }
}
