<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Vendor\Mcp;

use KimaiPlugin\McpServerBundle\Vendor\Mcp\Client\Builder;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Client\CancellationTokenInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Client\Configuration;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Client\Protocol;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Client\Transport\TransportInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\ConnectionException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\ExceptionInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\InvalidArgumentException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\RequestCancelledException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\RequestException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\RuntimeException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Enum\LoggingLevel;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Enum\ProtocolVersion;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Implementation;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\Error;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\Request;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\Response;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Notification\RootsListChangedNotification;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\PromptReference;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\CallToolRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\CompletionCompleteRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\DiscoverRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\GetPromptRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\ListPromptsRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\ListResourcesRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\ListResourceTemplatesRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\ListToolsRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\PingRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\ReadResourceRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\SetLogLevelRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ResourceReference;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\CallToolResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\CompletionCompleteResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\GetPromptResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\ListPromptsResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\ListResourcesResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\ListResourceTemplatesResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\ListToolsResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\ReadResourceResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ServerCapabilities;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Log\LoggerInterface;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Log\NullLogger;

/**
 * Main MCP Client facade.
 *
 * Provides a synchronous API for communicating with MCP servers.
 * All blocking operations are delegated to the transport.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Client
{
    private const RETRY_BASE_DELAY_MS = 100;

    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly Protocol $protocol,
        private readonly Configuration $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Create a new client builder for fluent configuration.
     */
    public static function builder(): Builder
    {
        return new Builder();
    }

    /**
     * Connect to an MCP server using the provided transport.
     *
     * A failed attempt is closed and retried, see {@see Builder::setMaxRetries()}.
     *
     * @throws ConnectionException If connection or initialization fails on every attempt
     */
    public function connect(TransportInterface $transport): void
    {
        $this->transport = $transport;
        $this->protocol->connect($transport, $this->config);

        $maxAttempts = $this->config->maxRetries + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                $transport->connect();

                $this->logger->info('Client connected and initialized', ['attempt' => $attempt]);

                return;
            } catch (ExceptionInterface $e) {
                // initialize() flags the session before sending the initialized
                // notification, so a failure in between leaves the flag set.
                $this->protocol->getState()->setInitialized(false);

                $transport->close();

                // Anything else, like a malformed initialize result, is not
                // transient and would fail every retry the same way.
                if (!$e instanceof ConnectionException) {
                    throw new ConnectionException('Initialization failed: '.$e->getMessage(), 0, $e);
                }

                if ($attempt === $maxAttempts) {
                    throw $e;
                }

                $this->logger->warning('Connection attempt failed, retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'exception' => $e,
                ]);

                usleep($attempt * self::RETRY_BASE_DELAY_MS * 1000);
            }
        }
    }

    /**
     * Check if connected and initialized.
     */
    public function isConnected(): bool
    {
        return null !== $this->transport && $this->protocol->getState()->isInitialized();
    }

    /**
     * Get server information from initialization.
     */
    public function getServerInfo(): ?Implementation
    {
        return $this->protocol->getState()->getServerInfo();
    }

    /**
     * Get server instructions.
     */
    public function getInstructions(): ?string
    {
        return $this->protocol->getState()->getInstructions();
    }

    /**
     * Capabilities the server declared: during the handshake, or in its
     * `server/discover` result from 2026-07-28 on.
     *
     * Null until a server declared them, e.g. before connecting or when a
     * 2026-07-28 server does not answer `server/discover`.
     */
    public function getServerCapabilities(): ?ServerCapabilities
    {
        return $this->protocol->getState()->getServerCapabilities();
    }

    /**
     * Protocol revision negotiated during the handshake.
     *
     * This is the version the server answered with, which is not necessarily the
     * one configured on the builder: a server that cannot speak the requested
     * revision counter-offers one it supports. Null until the handshake completed.
     */
    public function getProtocolVersion(): ?ProtocolVersion
    {
        return $this->protocol->getState()->getProtocolVersion();
    }

    /**
     * Check that the server is reachable: `ping`, or `server/discover` on the modern era, which removed it.
     */
    public function ping(): void
    {
        $request = $this->protocol->isModern() ? new DiscoverRequest() : new PingRequest();

        $this->sendRequest($request);
    }

    /**
     * List available tools from the server.
     */
    public function listTools(?string $cursor = null): ListToolsResult
    {
        $request = new ListToolsRequest($cursor);

        $response = $this->sendRequest($request);

        $result = $response->result;

        // Filtered before parsing, because parsing is where a malformed
        // `x-mcp-header` annotation throws. One broken definition must cost the
        // caller that tool, not the whole listing (SEP-2243).
        if (\is_array($result['tools'] ?? null)) {
            $result['tools'] = $this->protocol->getToolCatalog()->record($result['tools']);
        }

        return ListToolsResult::fromArray($result);
    }

    /**
     * Call a tool on the server.
     *
     * Cancellation and deadlines are cooperative: on HTTP, blocking I/O has to
     * return before the transport can observe the interruption. An answer that
     * arrived for an interrupted call is discarded rather than returned.
     *
     * @param string                                                                  $name           Tool name
     * @param array<string, mixed>                                                    $arguments      Tool arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                                Optional callback for progress updates
     * @param CancellationTokenInterface|null                                         $cancellation   Non-blocking cancellation signal
     * @param float|null                                                              $timeoutSeconds Finite positive timeout replacing the default for this call
     *
     * @throws RequestCancelledException                                                      When cancellation is observed
     * @throws Exception\TimeoutException                                                     When the request timeout is observed to have expired
     * @throws RequestException|ConnectionException|InvalidArgumentException|RuntimeException
     */
    public function callTool(string $name, array $arguments = [], ?callable $onProgress = null, ?CancellationTokenInterface $cancellation = null, ?float $timeoutSeconds = null): CallToolResult
    {
        if ($cancellation?->isCancellationRequested()) {
            throw new RequestCancelledException('The client cancelled the request.');
        }

        if (null !== $timeoutSeconds && (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0)) {
            throw new InvalidArgumentException('The per-call timeout must be a finite positive number of seconds.');
        }

        $catalog = $this->protocol->getToolCatalog();

        // A tool the listing showed to be malformed is refused here rather than
        // sent: the client cannot produce the headers its annotations demand,
        // so the call could only go out misdescribed (SEP-2243).
        if ($catalog->isRejected($name)) {
            throw new RuntimeException(\sprintf('Refusing to call tool "%s": its "x-mcp-header" annotations are invalid (%s).', $name, $catalog->reasonFor($name)));
        }

        $request = new CallToolRequest($name, $arguments);

        $response = $this->sendRequest($request, $onProgress, $cancellation, $timeoutSeconds);

        return CallToolResult::fromArray($response->result);
    }

    /**
     * List available resources from the server.
     */
    public function listResources(?string $cursor = null): ListResourcesResult
    {
        $request = new ListResourcesRequest($cursor);

        $response = $this->sendRequest($request);

        return ListResourcesResult::fromArray($response->result);
    }

    /**
     * List available resource templates from the server.
     */
    public function listResourceTemplates(?string $cursor = null): ListResourceTemplatesResult
    {
        $request = new ListResourceTemplatesRequest($cursor);

        $response = $this->sendRequest($request);

        return ListResourceTemplatesResult::fromArray($response->result);
    }

    /**
     * Read a resource by URI.
     *
     * @param string                                                                  $uri        The resource URI
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     *
     * @throws RequestException|ConnectionException|InvalidArgumentException
     */
    public function readResource(string $uri, ?callable $onProgress = null): ReadResourceResult
    {
        if ('' === $uri) {
            throw new InvalidArgumentException('Resource URI must not be empty.');
        }

        $request = new ReadResourceRequest($uri);

        $response = $this->sendRequest($request, $onProgress);

        return ReadResourceResult::fromArray($response->result);
    }

    /**
     * List available prompts from the server.
     */
    public function listPrompts(?string $cursor = null): ListPromptsResult
    {
        $request = new ListPromptsRequest($cursor);

        $response = $this->sendRequest($request);

        return ListPromptsResult::fromArray($response->result);
    }

    /**
     * Get a prompt from the server.
     *
     * @param string                                                                  $name       Prompt name
     * @param array<string, string>                                                   $arguments  Prompt arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     *
     * @throws RequestException|ConnectionException|InvalidArgumentException
     */
    public function getPrompt(string $name, array $arguments = [], ?callable $onProgress = null): GetPromptResult
    {
        $request = new GetPromptRequest($name, $arguments);

        $response = $this->sendRequest($request, $onProgress);

        return GetPromptResult::fromArray($response->result);
    }

    /**
     * Request completion suggestions for a prompt or resource argument.
     *
     * @param PromptReference|ResourceReference  $ref      The prompt or resource reference
     * @param array{name: string, value: string} $argument The argument to complete
     *
     * @throws RequestException|ConnectionException|InvalidArgumentException
     */
    public function complete(PromptReference|ResourceReference $ref, array $argument): CompletionCompleteResult
    {
        $request = new CompletionCompleteRequest($ref, $argument);

        $response = $this->sendRequest($request);

        return CompletionCompleteResult::fromArray($response->result);
    }

    /**
     * Set the minimum logging level for server log messages; on the modern era it rides on every following request.
     */
    public function setLoggingLevel(LoggingLevel $level): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('Client is not connected. Call connect() first.');
        }

        if ($this->protocol->isModern()) {
            $this->protocol->setLogLevel($level);

            return;
        }

        $request = new SetLogLevelRequest($level);

        $this->sendRequest($request);
    }

    /**
     * Notify the server that the client's list of roots has changed.
     *
     * The server should react by requesting an updated list via roots/list.
     *
     * @throws RuntimeException    if the client did not advertise the `roots.listChanged` capability
     * @throws ConnectionException if the client is not connected
     */
    public function sendRootsListChanged(): void
    {
        if (true !== $this->config->capabilities->rootsListChanged) {
            throw new RuntimeException('Cannot send a "roots/list_changed" notification without advertising the "roots.listChanged" capability. Build the client with new ClientCapabilities(roots: true, rootsListChanged: true).');
        }

        if (!$this->isConnected()) {
            throw new ConnectionException('Client is not connected. Call connect() first.');
        }

        if ($this->protocol->isModern()) {
            $this->logger->debug('Not sending "notifications/roots/list_changed": the connection is on the modern era, which removed roots.');

            return;
        }

        $this->protocol->sendNotification(new RootsListChangedNotification());
    }

    /**
     * Send a request to the server and wait for response.
     *
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *
     * @return Response<mixed>
     *
     * @throws RequestException|ConnectionException|InvalidArgumentException
     */
    private function sendRequest(Request $request, ?callable $onProgress = null, ?CancellationTokenInterface $cancellation = null, ?float $timeoutSeconds = null): Response
    {
        $transport = $this->transport;
        if (null === $transport || !$this->protocol->getState()->isInitialized()) {
            throw new ConnectionException('Client is not connected. Call connect() first.');
        }

        $withProgress = null !== $onProgress;
        $fiber = new \Fiber(fn () => $this->protocol->request($request, $this->config->requestTimeout, $withProgress, $cancellation, $timeoutSeconds ?? $this->config->requestTimeout));
        $this->protocol->setProgressCallback($onProgress);

        try {
            $response = $transport->runRequest($fiber);
        } finally {
            $this->protocol->setProgressCallback(null);
        }

        if ($response instanceof Error) {
            throw RequestException::fromError($response);
        }

        return $response;
    }

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void
    {
        if (null !== $this->transport) {
            $this->transport->close();
            $this->transport = null;
            $this->logger->info('Client disconnected');
        }
    }
}
