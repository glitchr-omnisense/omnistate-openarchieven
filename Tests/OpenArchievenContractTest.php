<?php

namespace Omnistate\OpenArchieven\Tests;

use Omnistate\Model\CivilQuery;
use Omnistate\OpenArchieven\OpenArchieven;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on the register's recorded answers (Tests/Fixtures). */
final class OpenArchievenContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new OpenArchieven($http);
    }

    protected function recordedAnswers(): array
    {
        return ['*' => static fn (string $method, string $url) => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match (true) {
            str_contains($url, '/show.json') => 'show.json',
            default => 'search.json',
        }), ['response_headers' => ['content-type' => 'application/json']])];
    }

    protected function civilQuery(): ?CivilQuery
    {
        return new CivilQuery(familyName: 'van Gogh', givenName: 'Vincent Willem');
    }

    protected function civilIdentifier(): ?string
    {
        return 'bhi:78a37833-d731-ffe5-184c-f9f424826871';
    }
}
