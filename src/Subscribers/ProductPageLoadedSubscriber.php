<?php declare(strict_types=1);

namespace AydJtlConnector\Subscribers;

use AydJtlConnector\Service\JtlApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductPageLoadedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JtlApiClient $client,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onLoaded'];
    }

    public function onLoaded(ProductPageLoadedEvent $event): void
    {
        if (!(bool) $this->config->get('JtlDetail.config.enableOnPdp')) {
            return;
        }

        $product = $event->getPage()->getProduct();
        if (!$product) return;

        $sku = $product->getProductNumber() ?? '';
        if ($sku === '') return;

        try {
            $item = $this->client->getItemBySku($sku);
            if (!$item) return;

            $stock = $this->client->getStockByItemId((int) $item['id']);

            $payload = [
                'jtlSku'      => $item['sku'] ?? null,
                'jtlItemId'   => $item['id'] ?? null,
                'stock'       => $stock['free'] ?? null,
                'stockByWh'   => $stock['byWarehouse'] ?? [],
            ];

            $event->getPage()->addExtension('jtlData', new ArrayStruct($payload));
        } catch (\Throwable $e) {
            $this->logger->warning('JTL PDP enrichment failed: ' . $e->getMessage(), ['sku' => $sku]);
        }
    }
}

