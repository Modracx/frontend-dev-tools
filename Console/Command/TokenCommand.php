<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Console\Command;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Modracx\FrontendDevTools\Model\AccessGate;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the link that opts a browser in.
 *
 * On the command line rather than in the admin because that is where the person who needs it
 * already is, and because putting it in the admin would mean any backend user with config
 * access could hand out storefront profiling to anyone.
 */
class TokenCommand extends Command
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly ScopeConfigInterface $scopeConfig,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('modracx:frontend:token')
            ->setDescription('Show the URL that enables the frontend developer toolbar in a browser');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $this->gate->expectedToken();

        $output->writeln('');
        $output->writeln('<info>Frontend dev tools access token</info>');
        $output->writeln('  ' . $token);
        $output->writeln('');
        $output->writeln('<info>Open this in the browser you want the toolbar in:</info>');

        foreach ($this->baseUrls() as $baseUrl) {
            $output->writeln(sprintf(
                '  %smodracx_fdt/access/enable?token=%s',
                rtrim($baseUrl, '/') . '/',
                $token
            ));
        }

        $output->writeln('');
        $output->writeln('Your IP must also be listed under Stores > Configuration > Advanced >');
        $output->writeln('Modracx Frontend Dev Tools, and the site must not be in production mode');
        $output->writeln('unless that section explicitly allows it.');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Base URLs read straight from configuration.
     *
     * The obvious implementation — set the frontend area, then walk StoreManager::getStores()
     * and call getBaseUrl() on each — hangs on this stack: asking for a store's URL from the
     * CLI drags in enough of the frontend to stall the command indefinitely. Configuration is
     * the same data one layer down, needs no area, and cannot block. The link works on any
     * store view regardless of which base URL it is built from, so nothing is lost.
     *
     * @return list<string>
     */
    private function baseUrls(): array
    {
        $urls = [];

        foreach (['web/secure/base_url', 'web/unsecure/base_url'] as $path) {
            $value = (string)$this->scopeConfig->getValue($path);

            if ($value !== '' && !in_array($value, $urls, true)) {
                $urls[] = $value;
            }
        }

        return $urls !== [] ? $urls : ['/'];
    }
}
