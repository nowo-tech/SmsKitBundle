<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds the demo settings (idempotent): the site section and the SMS section on the log provider, so nothing ever
 * leaves the container. The test recipient is in the reserved fictional +1 555 01xx range.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed demo instance settings (idempotent)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = [
            ...$this->settings->save('site', [
                'name'        => 'Acme Ops',
                'footer'      => 'Acme Ops — status and incident tooling.',
                'support_url' => 'https://support.example.test',
                'page_size'   => 25,
                'theme'       => 'auto',
            ]),
            ...$this->settings->save('sms', [
                'provider'        => 'log',
                'sender'          => 'AcmeOps',
                'test_recipient'  => '+15550100123',
                'bridge_base_url' => 'https://sms-bridge.example.test',
                'bridge_api_mode' => 'twilio',
            ]),
        ];

        $output->writeln(sprintf('Demo instance settings seeded (%d value(s) changed).', count($changed)));

        return Command::SUCCESS;
    }
}
