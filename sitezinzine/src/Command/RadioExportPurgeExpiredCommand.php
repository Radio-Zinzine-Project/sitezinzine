<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPurge;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:radio-export:purge-expired', description: 'Purge les donnees RadioExport des semaines terminees.')]
final class RadioExportPurgeExpiredCommand extends Command
{
    public function __construct(
        private readonly RadioPreparationPurge $purge,
        private readonly ClockInterface $clock,
        #[Autowire('%env(RADIO_EXPORT_ROOT)%')] private readonly string $root,
        #[Autowire('%app.mp3_base_dir%')] private readonly string $library,
        #[Autowire('%app.mp3_work_dir%')] private readonly string $work,
        #[Autowire('%app.mp3_trash_dir%')] private readonly ?string $trash,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $public,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les semaines candidates sans ecrire ni supprimer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->root === '' || $this->trash === null) {
            $output->writeln('<error>Le stockage RadioExport et les racines audio doivent etre configures.</error>');
            return self::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $weeks = $this->purge->run($this->root,
                ['library' => $this->library, 'work' => $this->work, 'trash' => $this->trash, 'public' => $this->public],
                $this->clock->now(), $dryRun);
        } catch (\RuntimeException|\Doctrine\DBAL\Exception) {
            $output->writeln('<error>Impossible de recenser les semaines RadioExport a purger.</error>');
            return self::FAILURE;
        }

        $failed = false;
        foreach ($weeks as $week => $result) {
            $message = match ($result['status']) {
                'planned' => sprintf('%d fichier(s) recenses, confirmations a purger si presentes.', $result['files']),
                'purged' => sprintf('%d fichier(s) et %d confirmation(s) supprimes.', $result['files'], $result['confirmations']),
                'busy' => 'Semaine occupee, reportee au prochain passage.',
                default => sprintf('Purge non terminee (%s).', $result['reason']),
            };
            $output->writeln($week . ' - ' . $message, OutputInterface::OUTPUT_RAW);
            $failed = $failed || $result['status'] === 'failed';
        }
        if ($weeks === []) {
            $output->writeln('Aucune semaine a traiter.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
