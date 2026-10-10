<?php

namespace App\AnimeBundle\Service\Provider;

use App\AnimeBundle\Exception\ProviderFetchException;
use App\AnimeBundle\Exception\SiteUnavailableException;
use App\AnimeBundle\Service\AnimeDownloaderInterface;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract readonly class AbstractAnimeProvider implements AnimeDownloaderInterface
{
    protected HttpBrowser $httpBrowser;

    public function __construct(
        HttpClientInterface $httpClient,
        protected string    $websiteUrl,
        protected string    $websiteUrlRegex,
    ) {
        $this->httpBrowser = new HttpBrowser($httpClient);
    }

    /**
     * Fetch page from relative or absolute path and return a Crawler
     *
     * @param string $path
     * @return Crawler
     * @throws SiteUnavailableException
     * @throws ProviderFetchException
     */
    protected function fetchPage(string $path = ""): Crawler
    {
        $targetUrl = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $this->getWebsiteUrl() . $path;

        try {
            $crawler = $this->httpBrowser->request(Request::METHOD_GET, $targetUrl);
            $response = $this->httpBrowser->getResponse();
        } catch (TransportExceptionInterface | \Throwable $e) {
            // Handle network errors, timeouts, or DNS resolution issues
            throw new SiteUnavailableException(
                sprintf("[%s] Network/DNS error while contacting provider: %s", static::getServiceName(), $e->getMessage()),
                0,
                $e
            );
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            throw new SiteUnavailableException(
                sprintf("[%s] Site is currently unavailable (HTTP %d)", static::getServiceName(), $statusCode),
                $statusCode
            );
        }

        if ($statusCode !== Response::HTTP_OK) {
            throw new ProviderFetchException(static::getServiceName(), $statusCode, $targetUrl);
        }

        return $crawler;
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteUrlRegex(): string
    {
        return $this->websiteUrlRegex;
    }
}
