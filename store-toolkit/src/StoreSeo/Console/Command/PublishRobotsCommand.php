<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Console\Command;

use Scr1be\StoreSeo\Model\Robots\Publisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerates every website's robots.txt from configuration.
 *
 * The observer covers changes; this covers everything else — a fresh deployment onto an empty
 * media volume, a database imported from production, a file deleted by hand. It is the command a
 * deploy script runs after `setup:upgrade`.
 *
 * No area code is set because nothing on the publishing path needs one: the reads are scope
 * configuration and the writes are files, neither of which resolves a design theme or a frontend
 * URL.
 */
class PublishRobotsCommand extends Command
{
    private const COMMAND_NAME = 'scr1be:seo:robots:publish';

    private Publisher $publisher;

    public function __construct(Publisher $publisher, ?string $name = null)
    {
        $this->publisher = $publisher;

        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Write the configured robots.txt of every website to the media directory.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = $this->publisher->publishAll();

        if ($published === []) {
            $output->writeln('<comment>No robots.txt file was published. Check that the feature is enabled.</comment>');

            return Command::SUCCESS;
        }

        foreach ($published as $path) {
            $output->writeln('<info>Published</info> ' . $path);
        }

        return Command::SUCCESS;
    }
}
