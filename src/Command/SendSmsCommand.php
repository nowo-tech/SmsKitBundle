<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Command;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;
use function trim;

/**
 * `nowo:sms:send <to> <body>`: sends one SMS through the active provider (operator test). Prints the provider, the
 * masked destination and the provider message id / status; exits 1 when the provider is not configured or the send
 * fails.
 */
#[AsCommand(name: self::NAME, description: 'Send an SMS through the configured provider (test / operator tool).')]
final class SendSmsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:sms:send';

    /**
     * @param SmsSenderInterface $sender SMS sender
     */
    public function __construct(
        private readonly SmsSenderInterface $sender,
    ) {
        parent::__construct();
    }

    /**
     * Arguments and options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Destination number in E.164 format (+15550100123)')
            ->addArgument('body', InputArgument::REQUIRED, 'Message text')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Sender override (E.164 number or alphanumeric sender id)')
            ->addOption('device-id', null, InputOption::VALUE_REQUIRED, 'SMS Bridge device id override')
            ->addOption('client-message-id', null, InputOption::VALUE_REQUIRED, 'Idempotency id forwarded to providers that support it');
    }

    /**
     * Sends the message.
     *
     * @param InputInterface $input console input
     * @param OutputInterface $output console output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->note(sprintf('Provider: %s (configured: %s)', $this->sender->getProviderId(), $this->sender->isConfigured() ? 'yes' : 'no'));

        if (!$this->sender->isConfigured()) {
            $io->error('The SMS provider is not configured: choose a provider and its credentials in the "sms" instance settings section (or the nowo_sms_kit.providers fallbacks).');

            return Command::FAILURE;
        }

        $to = (string) $input->getArgument('to');
        try {
            $result = $this->sender->send(new SmsMessage(
                $to,
                (string) $input->getArgument('body'),
                $this->option($input, 'from'),
                $this->option($input, 'device-id'),
                $this->option($input, 'client-message-id'),
            ));
        } catch (SmsSendException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Sent via %s to %s: id=%s status=%s', $result->providerId, E164::mask($to), $result->providerMessageId === '' ? '-' : $result->providerMessageId, $result->status));

        return Command::SUCCESS;
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
