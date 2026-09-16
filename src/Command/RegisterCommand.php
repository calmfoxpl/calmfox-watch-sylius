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

/**
 * Aktywacja pakietu Free bez wchodzenia do panelu. Na tej platformie wdrożenia
 * bywają w pełni skryptowe, więc każda operacja z ekranu ma odpowiednik w CLI.
 */
#[AsCommand(name: 'calmfox:watch:register', description: 'Aktywuje pakiet Free i łączy sklep z Calmfox Watch')]
final class RegisterCommand extends Command
{
    public function __construct(private readonly HubClient $hub)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail właściciela sklepu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Podaj poprawny adres e-mail.');

            return Command::INVALID;
        }

        $io->text(sprintf('Adres kontrolny zgłaszany do Calmfox Watch: %s', $this->hub->healthUrl()));
        $result = $this->hub->register($email);

        if (!$result['ok']) {
            $io->error($result['message']);

            return Command::FAILURE;
        }
        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
