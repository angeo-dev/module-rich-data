<?php

declare(strict_types=1);

namespace Angeo\RichData\Console\Command;

use Angeo\RichData\Model\JsonLd\JsonEncoder;
use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Angeo\RichData\Model\Page\BreadcrumbTrailBuilder;
use Angeo\RichData\Model\Page\CategoryProductsProvider;
use Angeo\RichData\Model\Page\CurrentEntity;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Cms\Model\GetPageByIdentifier;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validates the JSON-LD this module produces for a product, category or CMS page.
 */
class ValidateCommand extends Command
{
    private const OPT_STORE       = 'store';
    private const OPT_PRODUCT_ID  = 'product-id';
    private const OPT_CATEGORY_ID = 'category-id';
    private const OPT_CMS         = 'cms-identifier';
    private const OPT_JSON        = 'json';

    public function __construct(
        private readonly SchemaRenderer $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly GetPageByIdentifier $getPageByIdentifier,
        private readonly BreadcrumbTrailBuilder $breadcrumbTrailBuilder,
        private readonly CategoryProductsProvider $categoryProductsProvider,
        private readonly JsonEncoder $jsonEncoder,
        private readonly Emulation $emulation,
        private readonly State $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:rich-data:validate')
            ->setDescription('Validate the JSON-LD output for a product, category or CMS page.')
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_OPTIONAL, 'Store code (default: default)')
            ->addOption(self::OPT_PRODUCT_ID, 'p', InputOption::VALUE_OPTIONAL, 'Product ID (default: a random visible product)')
            ->addOption(self::OPT_CATEGORY_ID, 'c', InputOption::VALUE_OPTIONAL, 'Category ID, validates a category page instead')
            ->addOption(self::OPT_CMS, null, InputOption::VALUE_OPTIONAL, 'CMS page identifier, validates a CMS page instead')
            ->addOption(self::OPT_JSON, null, InputOption::VALUE_NONE, 'Print the raw JSON-LD only, for piping into other tools');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('frontend');
        } catch (\Throwable) {
            // already set
        }

        $storeCode = (string) ($input->getOption(self::OPT_STORE) ?: 'default');

        try {
            $store = $this->storeManager->getStore($storeCode);
        } catch (\Throwable $e) {
            $output->writeln('<error>Unknown store: ' . $storeCode . '</error>');

            return Command::FAILURE;
        }

        $this->emulation->startEnvironmentEmulation((int) $store->getId(), 'frontend', true);

        try {
            $context = $this->buildContext($input, $store, $output);
            if ($context === null) {
                return Command::FAILURE;
            }

            $nodes = $this->schemaRenderer->collect($store, $context);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        if ($nodes === []) {
            $output->writeln('<error>No JSON-LD produced. Check that the module and the relevant schema group are enabled.</error>');

            return Command::FAILURE;
        }

        $graphMode = $this->schemaRenderer->isGraphMode($store);
        $document  = $graphMode
            ? ['@context' => 'https://schema.org', '@graph' => array_map($this->stripContext(...), $nodes)]
            : $nodes;

        if ($input->getOption(self::OPT_JSON)) {
            $output->writeln($this->jsonEncoder->encode($document));

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Store:</info>      %s', $store->getCode()));
        $output->writeln(sprintf('<info>Page URL:</info>   %s', (string) ($context['page_url'] ?? '')));
        $output->writeln(sprintf('<info>Output mode:</info> %s', $graphMode ? 'graph' : 'legacy'));
        $output->writeln('');

        $issues = $this->report($nodes, $graphMode, $output);

        $output->writeln('');
        $output->writeln('<comment>Full output:</comment>');
        $output->writeln($this->jsonEncoder->encode($document));
        $output->writeln('');

        if ($issues > 0) {
            $output->writeln(sprintf('<error>%d issue(s) found.</error>', $issues));

            return Command::FAILURE;
        }

        $output->writeln('<info>All JSON-LD nodes look valid.</info>');

        return Command::SUCCESS;
    }

    private function buildContext(InputInterface $input, StoreInterface $store, OutputInterface $output): ?array
    {
        $categoryId = $input->getOption(self::OPT_CATEGORY_ID);
        if ($categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, (int) $store->getId());
            } catch (\Throwable $e) {
                $output->writeln('<error>Category not found: ' . $categoryId . '</error>');

                return null;
            }

            return [
                'page_type'         => CurrentEntity::PAGE_CATEGORY,
                'page_url'          => (string) $category->getUrl(),
                'category'          => $category,
                'category_products' => $this->categoryProductsProvider->getProducts($category, $store),
                'breadcrumbs'       => $this->breadcrumbTrailBuilder->forCategory($category, $store),
            ];
        }

        $cmsIdentifier = $input->getOption(self::OPT_CMS);
        if ($cmsIdentifier) {
            try {
                $page = $this->getPageByIdentifier->execute((string) $cmsIdentifier, (int) $store->getId());
            } catch (\Throwable $e) {
                $output->writeln('<error>CMS page not found: ' . $cmsIdentifier . '</error>');

                return null;
            }

            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

            return [
                'page_type'           => CurrentEntity::PAGE_CMS,
                'page_url'            => $baseUrl . '/' . ltrim((string) $cmsIdentifier, '/'),
                'cms_page_identifier' => (string) $page->getIdentifier(),
                'cms_page_content'    => (string) $page->getContent(),
                'breadcrumbs'         => $this->breadcrumbTrailBuilder->forCmsPage((string) $page->getTitle(), $store),
            ];
        }

        $product = $this->loadProduct($input->getOption(self::OPT_PRODUCT_ID));
        if ($product === null) {
            $output->writeln('<error>No visible products found.</error>');

            return null;
        }

        return [
            'page_type'   => CurrentEntity::PAGE_PRODUCT,
            'page_url'    => (string) $product->getProductUrl(),
            'product'     => $product,
            'breadcrumbs' => $this->breadcrumbTrailBuilder->forProduct($product, $store),
        ];
    }

    /**
     * @param array<int, array> $nodes
     * @return int number of issues
     */
    private function report(array $nodes, bool $graphMode, OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>Found %d node(s):</info>', count($nodes)));

        $issues = 0;
        $ids    = [];

        foreach ($nodes as $index => $node) {
            $type = (string) ($node['@type'] ?? 'unknown');
            $id   = (string) ($node['@id'] ?? '');

            $output->writeln(sprintf('  Node %d: <info>%s</info>', $index + 1, $type));

            if ($id === '') {
                $output->writeln('    <comment>WARN</comment> node has no @id, so nothing can reference it');
                $issues++;
            } elseif (isset($ids[$id])) {
                $output->writeln('    <error>FAIL</error> duplicate @id: ' . $id);
                $issues++;
            } else {
                $ids[$id] = true;
            }

            if ($type === 'Product' || $type === 'ProductGroup') {
                $issues += $this->reportProduct($node, $type, $output);
            }
        }

        if ($graphMode && count($nodes) > 1) {
            $output->writeln('  <info>All nodes are merged into one @graph document.</info>');
        }

        return $issues;
    }

    private function reportProduct(array $node, string $type, OutputInterface $output): int
    {
        $issues = 0;

        foreach (['name', 'description', 'image', 'url'] as $field) {
            if (empty($node[$field])) {
                $output->writeln(sprintf('    <comment>WARN</comment> missing %s', $field));
                $issues++;
            }
        }

        if ($type === 'ProductGroup') {
            if (empty($node['hasVariant'])) {
                $output->writeln('    <error>FAIL</error> ProductGroup without hasVariant');

                return $issues + 1;
            }

            $output->writeln(sprintf('    <info>PASS</info> %d variant(s)', count($node['hasVariant'])));

            foreach ($node['hasVariant'] as $position => $variant) {
                if (empty($variant['offers'])) {
                    $output->writeln(sprintf('    <error>FAIL</error> variant %d has no offers', $position + 1));
                    $issues++;
                }
            }

            return $issues;
        }

        $offers = $node['offers'] ?? [];
        if (!is_array($offers) || $offers === []) {
            $output->writeln('    <error>FAIL</error> missing offers, required for ChatGPT Shopping');

            return $issues + 1;
        }

        $offerType = (string) ($offers['@type'] ?? 'Offer');
        $required  = $offerType === 'AggregateOffer'
            ? ['lowPrice', 'highPrice', 'priceCurrency', 'availability']
            : ['price', 'priceCurrency', 'availability'];

        foreach ($required as $field) {
            if (!isset($offers[$field]) || $offers[$field] === '') {
                $output->writeln(sprintf('    <error>FAIL</error> missing offers.%s', $field));
                $issues++;
            }
        }

        if (isset($node['aggregateRating'])) {
            $output->writeln('    <info>PASS</info> aggregateRating present');
        }

        return $issues;
    }

    private function stripContext(array $node): array
    {
        unset($node['@context']);

        return $node;
    }

    private function loadProduct(?string $productId)
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*')
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', [
                'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
            ])
            ->addUrlRewrite()
            ->setPageSize(1);

        if ($productId) {
            $collection->addFieldToFilter('entity_id', (int) $productId);
        } else {
            $collection->getSelect()->orderRand();
        }

        $product = $collection->getFirstItem();

        return $product->getId() ? $product : null;
    }
}
