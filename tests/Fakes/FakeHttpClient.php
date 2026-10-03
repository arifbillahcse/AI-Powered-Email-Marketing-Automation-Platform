<?php

namespace Tests\Fakes;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client for the Anthropic SDK: returns queued responses and
 * records every request.
 */
class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    protected array $responses = [];

    public function push(int $status, array $body): static
    {
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], json_encode($body));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(500, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"api_error","message":"No fake response queued"}}');
    }

    /**
     * @return array<string, mixed>
     */
    public function lastBody(): array
    {
        $request = end($this->requests);

        return json_decode((string) $request->getBody(), true);
    }
}
