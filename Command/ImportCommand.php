<?php

declare(strict_types=1);

/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Max107\Bundle\TranslationBundle\Command;

use Max107\Bundle\TranslationBundle\Components\CsvLoader;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

/**
 * Class ImportCommand.
 */
class ImportCommand extends ContainerAwareCommand
{
    protected static $defaultName = 'translation:import';

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    protected function configure()
    {
        $this
            ->setDescription('Import translations from CSV files to project bundles')
            ->addArgument('locales', InputArgument::REQUIRED, 'Locales to import from CSV file to bundles')
            ->addArgument('csv', InputArgument::REQUIRED, 'Output CSV filename')
            ->addOption('domains', null, InputOption::VALUE_OPTIONAL, 'Domains', 'all')
            ->addOption('bundles', null, InputOption::VALUE_OPTIONAL, 'Limit to bundles', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $service = $this->getContainer()->get('kilik.translation.services.load_translation_service');
        $fs = $this->getContainer()->get('filesystem');

        $bundlesNames = explode(',', $input->getOption('bundles'));
        $domains = explode(',', $input->getOption('domains'));
        $locales = explode(',', $input->getArgument('locales'));

        // load CSV file
        $importTranslations = CsvLoader::load($input->getArgument('csv'), $bundlesNames, $domains, $locales);

        // load translations for matched bundles
        $bundles = [];

        // load existing translations on working bundles
        foreach ($importTranslations as $bundleName => $notused) {
            $bundle = $this->getApplication()->getKernel()->getBundle($bundleName);
            $bundles[$bundleName] = $bundle;
        }

        $service->loadBundlesTranslationFiles($bundles, $locales, $domains);

        // merge translations
        $allTranslations = array_replace_recursive($service->getTranslations(), $importTranslations);

        // rewrite files (Bundle/domain.locale.yml)
        foreach ($allTranslations as $bundleName => $bundleTranslations) {
            foreach ($bundleTranslations as $domain => $domainTranslations) {
                // sort translations
                ksort($domainTranslations);

                foreach ($locales as $locale) {
                    // prepare array (only for locale)
                    $localTranslations = [];
                    foreach ($domainTranslations as $key => $localeTranslation) {
                        if (isset($localeTranslation[$locale])) {
                            $this->assignArrayByPath($localTranslations, $key, $localeTranslation[$locale]);
                        }
                    }

                    // determines destination file name
                    $bundle = $bundles[$bundleName];
                    $basePath = $bundle->getPath().'/Resources/translations';
                    $filePath = $basePath.'/'.$domain.'.'.$locale.'.yml';
                    if (!$fs->exists($basePath)) {
                        $fs->mkdir($basePath);
                    }

                    // prepare
                    $ymlDumper = new Dumper(2);
                    $ymlContent = $ymlDumper->dump($localTranslations, 10);

                    $originalSha1 = null;
                    if (file_exists($filePath)) {
                        $originalSha1 = sha1_file($filePath);
                    }
                    file_put_contents($filePath, $ymlContent);
                    $newSha1 = sha1_file($filePath);
                    if ($newSha1 != $originalSha1) {
                        $output->writeln('<info>'.$filePath.' updated</info>');
                    }
                }
            }
        }
    }

    /**
     * @param array  $arr
     * @param string $path
     * @param string $value
     * @param string $delimiter
     * @param string $escape
     */
    public function assignArrayByPath(&$arr, $path, $value, $delimiter = '.', $escape = '\\')
    {
        $keys = explode($delimiter, $path);

        foreach ($keys as $key) {
            $arr = &$arr[$key];
        }

        $arr = $value;
    }
}
