<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Command;

use Calmfox\WatchBundle\Hub\HubClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Połączenie z istniejącą stroną w panelu kluczem instalacyjnym fxp_live_… */
#[AsCommand(name: 'calmfox:watch:pair', description: 'Łączy sklep z istniejącą stroną w panelu Calmfox Watch')]
final class PairCommand extends Command
{
    public function __construct(private readonly HubClient $hub)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('token', InputArgument::OPTIONAL, 'Klucz instalacyjny z ekranu Integracje (fxp_live_…). Bez niego użyjemy zapamiętanego.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = trim((string) $input->getArgument('token'));

        if ('' !== $token && 1 !== preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', $token)) {
            $io->error('Klucz ma inny format niż fxp_live_… Skopiuj go z ekranu Integracje w panelu.');

            return Command::INVALID;
        }

        $io->text(sprintf('Adres kontrolny zgłaszany do Calmfox Watch: %s', $this->hub->healthUrl()));
        $result = $this->hub->pair($token);

        if (!$result['ok']) {
            $io->error($result['message']);

            return Command::FAILURE;
        }
        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
