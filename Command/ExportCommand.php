<?php

declare(strict_types=1);

/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Max107\Bundle\TranslationBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ExportCommand.
 */
class ExportCommand extends ContainerAwareCommand
{
    protected static $defaultName = 'translation:export';

    protected function configure()
    {
        $this
            ->setDescription('Export translations from project bundles to CSV file')
            ->addArgument('locale', InputArgument::REQUIRED, 'Locale used as reference in application')
            ->addArgument('locales', InputArgument::REQUIRED, 'Locales to export missing translations')
            ->addArgument('bundles', InputArgument::REQUIRED, 'Bundles scope')
            ->addArgument('csv', InputArgument::REQUIRED, 'Output CSV filename')
            ->addOption('domains', null, InputOption::VALUE_OPTIONAL, 'Domains', 'all')
            ->addOption('only-missing', null, InputOption::VALUE_NONE, 'Export only missing translations');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bundlesNames = explode(',', $input->getArgument('bundles'));

        $service = $this->getContainer()->get('kilik.translation.services.load_translation_service');

        $locale = $input->getArgument('locale');
        $locales = explode(',', $input->getArgument('locales'));
        $domains = explode(',', $input->getOption('domains'));

        // load all translations
        foreach ($bundlesNames as $bundleName) {
            $bundle = $this->getApplication()->getKernel()->getBundle($bundleName);

            // locales to export
            $service->loadBundleTranslationFiles($bundle, $locales, $domains);
            // locale reference
            $service->loadBundleTranslationFiles($bundle, [$locale], $domains);
        }

        // and export data as CSV (tab separated values)
        $columns = ['Bundle', 'Domain', 'Key', $locale];
        foreach ($locales as $localeColumn) {
            $columns[] = $localeColumn;
        }

        $buffer = implode(';', $columns).PHP_EOL;

        foreach ($service->getTranslations() as $bundleName => $domains) {
            foreach ($domains as $domain => $translations) {
                foreach ($translations as $trKey => $trLocales) {
                    $missing = false;

                    $data = [$bundleName, $domain, $trKey];
                    if (isset($trLocales[$locale])) {
                        $data[] = $this->fixMultiLine($trLocales[$locale]);
                    } else {
                        $data[] = '';
                        $missing = true;
                    }

                    foreach ($locales as $trLocale) {
                        if (isset($trLocales[$trLocale])) {
                            $data[] = $this->fixMultiLine($trLocales[$trLocale]);
                        } else {
                            $data[] = '';
                            $missing = true;
                        }
                    }

                    if (!$input->getOption('only-missing') || $missing) {
                        $buffer .= implode(';', $data).PHP_EOL;
                    }
                }
            }
        }
        file_put_contents($input->getArgument('csv'), $buffer);
        $output->writeln('<info>Saving translations to : '.$input->getArgument('csv').' (CSV tab separated value).</info>');
    }

    /**
     * Makes sure translation files with multi line strings result in correct csv files.
     *
     * @param string $str
     *
     * @return string
     */
    protected function fixMultiLine($str)
    {
        $str = str_replace(PHP_EOL, '\\n', $str);
        if ('\\n' === substr($str, -2)) {
            $str = substr($str, 0, -2); //Not doing this results in \n at the end of some strings after import.
        }

        return $str;
    }
}
