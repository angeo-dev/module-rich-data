<?php

declare(strict_types=1);

namespace Angeo\RichData\Console\Command;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateCommand extends Command
{
    private const OPT_STORE      = 'store';
    private const OPT_PRODUCT_ID = 'product-id';

    public function __construct(
        private readonly SchemaRenderer        $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory     $collectionFactory,
        private readonly State                 $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:rich-data:validate')
            ->setDescription('Validate JSON-LD schema output for a product page.')
            ->addOption(self::OPT_STORE,      's', InputOption::VALUE_OPTIONAL, 'Store code (default: default)')
            ->addOption(self::OPT_PRODUCT_ID, 'p', InputOption::VALUE_OPTIONAL, 'Product ID (default: random visible product)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('frontend');
        } catch (\Exception) {}

        $storeCode = $input->getOption(self::OPT_STORE) ?: 'default';
        $store     = $this->storeManager->getStore($storeCode);

        $productId = $input->getOption(self::OPT_PRODUCT_ID);
        $product   = $this->loadProduct($productId);

        if (!$product) {
            $output->writeln('<error>No visible products found.</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Validating JSON-LD for:</info> [%d] %s',
            $product->getId(),
            $product->getName()
        ));
        $output->writeln(sprintf('  Store:   <comment>%s</comment>', $store->getCode()));
        $output->writeln(sprintf('  URL:     <comment>%s</comment>', $product->getProductUrl()));
        $output->writeln('');

        $context = [
            'product'   => $product,
            'page_type' => 'catalog_product_view',
        ];

        $html = $this->schemaRenderer->render($store, $context);

        if (!$html) {
            $output->writeln('<error>No JSON-LD output generated. Check that module is enabled.</error>');
            return Command::FAILURE;
        }

        // Extract and validate each <script> block
        preg_match_all(
            '/<script type="application\/ld\+json">(.*?)<\/script>/si',
            $html,
            $matches
        );

        $output->writeln(sprintf('<info>Found %d JSON-LD block(s):</info>', count($matches[1])));
        $output->writeln('');

        $allValid = true;
        foreach ($matches[1] as $i => $json) {
            $decoded = json_decode(trim($json), true);
            $type    = $decoded['@type'] ?? 'unknown';

            if ($decoded === null) {
                $output->writeln(sprintf('  Block %d: <error>INVALID JSON</error>', $i + 1));
                $allValid = false;
                continue;
            }

            $output->writeln(sprintf('  Block %d: <info>@type:%s</info> — valid JSON ✓', $i + 1, $type));

            // Specific Product schema validation
            if ($type === 'Product') {
                $this->validateProduct($decoded, $output);
            }
        }

        $output->writeln('');

        if ($allValid) {
            $output->writeln('<info>All JSON-LD blocks are valid.</info>');
        } else {
            $output->writeln('<error>Some blocks have issues — check output above.</error>');
        }

        $output->writeln('');
        $output->writeln('<comment>Full output:</comment>');
        $output->writeln($html);
        $output->writeln('');

        return $allValid ? Command::SUCCESS : Command::FAILURE;
    }

    private function validateProduct(array $schema, OutputInterface $output): void
    {
        $required = ['name', 'description', 'image', 'url', 'offers'];
        $offerRequired = ['price', 'priceCurrency', 'availability'];

        foreach ($required as $field) {
            if (empty($schema[$field])) {
                $output->writeln(sprintf('    <comment>WARN</comment> Missing field: %s', $field));
            }
        }

        $offers = $schema['offers'] ?? [];
        if (is_array($offers)) {
            $offerData = isset($offers['@type']) ? $offers : ($offers[0] ?? []);
            foreach ($offerRequired as $field) {
                if (empty($offerData[$field])) {
                    $output->writeln(sprintf(
                        '    <error>FAIL</error> Missing offers.%s — required for ChatGPT Shopping',
                        $field
                    ));
                }
            }
        }

        if (isset($schema['aggregateRating'])) {
            $output->writeln('    <info>PASS</info> aggregateRating present');
        } else {
            $output->writeln('    <comment>INFO</comment> No aggregateRating (no reviews, or disabled)');
        }
    }

    private function loadProduct(?string $productId)
    {
        $collection = $this->collectionFactory->create();
        $collection
            ->addAttributeToSelect('*')
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
