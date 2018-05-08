<?php

declare(strict_types=1);

/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Max107\Bundle\TranslationBundle\Components;

/**
 * Class CsvLoader
 */
class CsvLoader
{
    /**
     * Load CSV File.
     *
     * @param       $filepath
     * @param array $bundles  bundles names to load
     * @param array $domains  domains to load
     * @param array $locales  locales to load
     *
     * @throws \Exception
     *
     * @return array
     */
    public static function load($filepath, $bundles, $domains, $locales)
    {
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $localesKeys = [];
        $columnsKeys = null;

        $translations = [];

        foreach ($lines as $line) {
            $row = explode("\t", $line);
            // detect columns names
            if (is_null($columnsKeys)) {
                $columnsKeys = [];
                foreach ($row as $key => $columnName) {
                    $columnsKeys[$columnName] = $key;
                }

                // check mandatory columns
                foreach (['Bundle', 'Domain', 'Key'] as $mandatoryColumn) {
                    if (!in_array($mandatoryColumn, $row)) {
                        throw new \Exception('mandatory column '.$mandatoryColumn.' is missing');
                    }
                }
                // check wanted locales
                foreach ($locales as $locale) {
                    $localeKey = array_search($locale, $row);
                    if (false === $localeKey) {
                        throw new \Exception('locale column '.$locale.' is missing');
                    }
                    // keep column id
                    $localesKeys[$locale] = $localeKey;
                }
            } // keep in memory translations
            else {
                $bundleName = $row[$columnsKeys['Bundle']];
                $domainName = $row[$columnsKeys['Domain']];
                if (in_array($bundleName, $bundles) || 1 == count($bundles) && 'all' == $bundles[0]) {
                    if (in_array($domainName, $domains) || 1 == count($domains) && 'all' == $domains[0]) {
                        foreach ($locales as $locale) {
                            $value = $row[$localesKeys[$locale]];
                            // keep only non blank translations
                            if ($value) {
                                // bundle / domain / key
                                $translations[$bundleName][$domainName][$row[$columnsKeys['Key']]][$locale] = $value;
                            }
                        }
                    }
                }
            }
        }

        return $translations;
    }
}
