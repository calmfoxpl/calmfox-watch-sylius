<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Command;

use Calmfox\WatchBundle\CalmfoxWatchBundle;
use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\Hub\HubClient;
use Calmfox\WatchBundle\Payload\PayloadProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Stan połączenia i skrót obu sekcji. Pierwsze polecenie po wdrożeniu. */
#[AsCommand(name: 'calmfox:watch:status', description: 'Stan połączenia z Calmfox Watch i skrót sprawdzeń')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly StateStore $state,
        private readonly SecretManager $secrets,
        private readonly HubClient $hub,
        private readonly PayloadProvider $payloads,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Calmfox Watch '.CalmfoxWatchBundle::VERSION);

        $connected = (bool) $this->state->get('connected', false);
        $lastPoll = $this->secrets->lastPollAt();

        $io->definitionList(
            ['Połączenie' => $connected ? 'aktywne' : 'brak'],
            ['Adres kontrolny' => $this->hub->healthUrl()],
            ['API' => $this->hub->apiUrl()],
            ['Pakiet' => (string) ($this->state->get('plan') ?: 'nieznany')],
            ['Katalog stanu' => $this->state->dir()],
            ['Ostatnie odpytanie' => null !== $lastPoll ? gmdate('Y-m-d H:i:s', $lastPoll).' UTC' : 'jeszcze nie było'],
        );

        $loopback = $this->hub->loopbackCheck();
        if (!$loopback['ok']) {
            $io->warning(sprintf('Sprawdzenie z serwera nie dociera do adresu kontrolnego: %s. Sprawdź reguły dostępu w security.yaml i zaporę.', $loopback['message']));
        }

        foreach ([PayloadProvider::SECTION_HEALTH => 'Stan usług', PayloadProvider::SECTION_SECURITY => 'Bezpieczeństwo'] as $section => $title) {
            $payload = $this->payloads->payload($section, true);
            $io->section(sprintf('%s: %s', $title, $payload['status']));
            $rows = [];
            foreach ($payload['checks'] as $check) {
                $rows[] = [$check['status'], $check['id'], $check['label'] ?? '', mb_substr((string) ($check['detail'] ?? ''), 0, 90)];
            }
            $io->table(['stan', 'identyfikator', 'nazwa', 'opis'], $rows);
        }

        return Command::SUCCESS;
    }
}
