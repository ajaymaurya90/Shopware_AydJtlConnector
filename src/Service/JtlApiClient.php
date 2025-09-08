<?php declare(strict_types=1);

namespace AydJtlConnector\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

class JtlApiClient
{
    private const CONFIG_PREFIX = 'AydJtlConnector.config.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->systemConfig->get(self::CONFIG_PREFIX . $key) ?? $default;
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->cfg('jtlBaseUrl', ''), '/');
    }

    private function headers(): array
    {
        $apiKey = (string) $this->cfg('jtlApiKey', '');
        $appId = (string) $this->cfg('jtlXAppId', 'MyApp/1.0.0');
        $appVersion = (string) $this->cfg('jtlXAppVersion', '1.0.0');

        if (!$apiKey || !$this->baseUrl()) {
            throw new \RuntimeException('JTL API not configured.');
        }

        return [
            'Authorization' => $apiKey,              // e.g. "Wawi 00000000-0000-0000-0000-000000000000"
            'X-AppId'       => $appId,
            'X-AppVersion'  => $appVersion,
            // Optional: 'X-RunAs' => '...'
        ];
    }

    /** Fetch first matching item by SKU (productNumber) */
    public function getItemBySku(string $sku): ?array
    {
        $ttl = (int) ($this->cfg('jtlTtl', 300));
        $cacheKey = 'jtl_item_' . md5($sku);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($sku, $ttl) {
            $item->expiresAfter($ttl);

            $url = $this->baseUrl() . '/items';
            $query = ['searchKeyWord' => $sku, 'pageSize' => 1];

            try {
                $res = $this->httpClient->request('GET', $url, [
                    'headers' => $this->headers(),
                    'query'   => $query,
                    'timeout' => 6.0,
                ])->toArray(false);
            } catch (TransportExceptionInterface|ClientExceptionInterface|ServerExceptionInterface $e) {
                $this->logger->error('JTL getItemBySku error: ' . $e->getMessage(), ['sku' => $sku]);
                return null;
            }

            if (!isset($res['items'][0])) {
                return null;
            }
            return $res['items'][0]; // contains id, sku, etc.
        });
    }

    /** Fetch stock info for a JTL itemId */
    public function getStockByItemId(int $itemId): ?array
    {
        $ttl = (int) ($this->cfg('cacheTtl', 300));
        $cacheKey = 'jtl_stock_' . $itemId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($itemId, $ttl) {
            $item->expiresAfter($ttl);

            $url = $this->baseUrl() . '/stocks';
            $query = ['kArtikel' => $itemId, 'pageSize' => 100];

            try {
                $res = $this->httpClient->request('GET', $url, [
                    'headers' => $this->headers(),
                    'query'   => $query,
                    'timeout' => 6.0,
                ])->toArray(false);
            } catch (TransportExceptionInterface|ClientExceptionInterface|ServerExceptionInterface $e) {
                $this->logger->error('JTL getStockByItemId error: ' . $e->getMessage(), ['itemId' => $itemId]);
                return null;
            }

            if (!isset($res['Items'])) {
                return null;
            }

            // Aggregate
            $sumTotal  = 0.0;
            $sumFree   = 0.0;
            $byWh = [];
            foreach ($res['Items'] as $row) {
                $total = (float) ($row['QuantityTotal'] ?? 0);
                $lockedAvail = (float) ($row['QuantityLockedForAvailability'] ?? 0);
                $inPicking   = (float) ($row['QuantityInPickingLists'] ?? 0);
                $free = max(0.0, $total - $lockedAvail - $inPicking);

                $sumTotal += $total;
                $sumFree  += $free;

                $wh = (string) ($row['WarehouseId'] ?? '0');
                $byWh[$wh] = ($byWh[$wh] ?? 0) + $free;
            }

            return [
                'total' => $sumTotal,
                'free'  => $sumFree,
                'byWarehouse' => $byWh,
                'raw' => $res['Items'],
            ];
        });
    }

}
