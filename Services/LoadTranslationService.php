<?php

declare(strict_types=1);

/*
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Max107\Bundle\TranslationBundle\Services;

use InvalidArgumentException;
use Symfony\Component\Config\Util\XmlUtils;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * Class LoadTranslationService.
 *
 * load translations from common yaml symfony resources files
 */
class LoadTranslationService
{
    /**
     * translations (bundle name/domain/key=>value).
     *
     * @var array
     */
    private $translations;

    /**
     * Root Dir.
     *
     * @var string
     */
    private $rootDir = null;

    /**
     * LoadTranslationService constructor.
     *
     * @param string $rootDir
     */
    public function __construct($rootDir)
    {
        $this->rootDir = $rootDir;
        $this->translations = [];
    }

    /**
     * Imports translation files form bundles.
     *
     * @param array $bundles
     * @param array $locales
     * @param array $domains
     */
    public function loadBundlesTranslationFiles($bundles, $locales, $domains)
    {
        foreach ($bundles as $bundle) {
            $this->loadBundleTranslationFiles($bundle, $locales, $domains);
        }
    }

    /**
     * Imports translation files form the specific bundles.
     *
     * @param BundleInterface $bundle
     * @param array           $locales
     * @param array           $domains
     */
    public function loadBundleTranslationFiles(BundleInterface $bundle, $locales, $domains)
    {
        $path = $bundle->getPath();
        $finder = $this->findTranslationsFiles($path, $locales, $domains);
        if (null === $finder) {
            return;
        }

        $this->loadTranslationFiles($bundle->getName(), $finder);
    }

    /**
     * Return a Finder object if $path has a Resources/translations folder.
     *
     * @param string $path
     * @param array  $locales
     * @param array  $domains
     * @param bool   $autocompletePath
     *
     * @return \Symfony\Component\Finder\Finder
     */
    protected function findTranslationsFiles($path, array $locales, array $domains, $autocompletePath = true)
    {
        $finder = null;
        if (preg_match('#^win#i', PHP_OS)) {
            $path = preg_replace('#'.preg_quote(DIRECTORY_SEPARATOR, '#').'#', '/', $path);
        }
        if (true === $autocompletePath) {
            $dir = (0 === strpos($path, $this->rootDir.'/Resources')) ? $path : $path.'/Resources/translations';
        } else {
            $dir = $path;
        }
        if (false === is_dir($dir)) {
            return null;
        }

        return (new Finder())
            ->files()
            ->name($this->getFileNamePattern($locales, $domains))
            ->in($dir);
    }

    /**
     * Imports some translations files.
     *
     * @param string $bundleName
     * @param Finder $finder
     */
    protected function loadTranslationFiles($bundleName, $finder)
    {
        foreach ($finder as $file) {
            list($domain, $locale, $extension) = explode('.', $file->getFilename());

            $this->loadTranslationFile($file, $bundleName, $extension, $domain, $locale);
        }
    }

    /**
     * Gets xliff file version based on the root "version" attribute.
     * Defaults to 1.2 for backwards compatibility.
     *
     * @throws InvalidArgumentException
     */
    private function getVersionNumber(\DOMDocument $dom): string
    {
        /** @var \DOMNode $xliff */
        foreach ($dom->getElementsByTagName('xliff') as $xliff) {
            $version = $xliff->attributes->getNamedItem('version');
            if ($version) {
                return $version->nodeValue;
            }

            $namespace = $xliff->attributes->getNamedItem('xmlns');
            if ($namespace) {
                if (0 !== substr_compare('urn:oasis:names:tc:xliff:document:', $namespace->nodeValue, 0, 34)) {
                    throw new InvalidArgumentException(sprintf('Not a valid XLIFF namespace "%s"', $namespace));
                }

                return substr($namespace, 34);
            }
        }

        // Falls back to v1.2
        return '1.2';
    }

    /**
     * Convert a UTF8 string to the specified encoding.
     * @param string $content
     * @param string|null $encoding
     *
     * @return string
     */
    private function utf8ToCharset(string $content, string $encoding = null): string
    {
        if ('UTF-8' !== $encoding && !empty($encoding)) {
            return mb_convert_encoding($content, $encoding, 'UTF-8');
        }

        return $content;
    }

    private function parseNotesMetadata(\SimpleXMLElement $noteElement = null, string $encoding = null): array
    {
        $notes = array();

        if (null === $noteElement) {
            return $notes;
        }

        /** @var \SimpleXMLElement $xmlNote */
        foreach ($noteElement as $xmlNote) {
            $noteAttributes = $xmlNote->attributes();
            $note = array('content' => $this->utf8ToCharset((string) $xmlNote, $encoding));
            if (isset($noteAttributes['priority'])) {
                $note['priority'] = (int) $noteAttributes['priority'];
            }

            if (isset($noteAttributes['from'])) {
                $note['from'] = (string) $noteAttributes['from'];
            }

            $notes[] = $note;
        }

        return $notes;
    }

    private function extractXliff1(\DOMDocument $dom): array
    {
        $result = [];

        $xml = simplexml_import_dom($dom);
        $encoding = $dom->encoding ? strtoupper($dom->encoding) : null;

        $xml->registerXPathNamespace('xliff', 'urn:oasis:names:tc:xliff:document:1.2');
        foreach ($xml->xpath('//xliff:trans-unit') as $translation) {
            $attributes = $translation->attributes();

            if (!(isset($attributes['resname']) || isset($translation->source))) {
                continue;
            }

            $source = isset($attributes['resname']) && $attributes['resname'] ? $attributes['resname'] : $translation->source;
            // If the xlf file has another encoding specified, try to convert it because
            // simple_xml will always return utf-8 encoded values
            $target = $this->utf8ToCharset((string) (isset($translation->target) ? $translation->target : $source), $encoding);

            $result[(string) $source] = $target;
        }

        return $result;
    }

    private function extractXliff2(\DOMDocument $dom): array
    {
        $result = [];

        $xml = simplexml_import_dom($dom);
        $encoding = $dom->encoding ? strtoupper($dom->encoding) : null;

        $xml->registerXPathNamespace('xliff', 'urn:oasis:names:tc:xliff:document:2.0');

        foreach ($xml->xpath('//xliff:unit') as $unit) {
            foreach ($unit->segment as $segment) {
                $source = $segment->source;

                // If the xlf file has another encoding specified, try to convert it because
                // simple_xml will always return utf-8 encoded values
                $target = $this->utf8ToCharset((string) (isset($segment->target) ? $segment->target : $source), $encoding);

                $result[(string) $source] = $target;
            }
        }

        return $result;
    }

    /**
     * Load translation file.
     *
     * @param SplFileInfo $file
     * @param string      $bundleName
     * @param string      $domain
     * @param string      $locale
     */
    protected function loadTranslationFile(SplFileInfo $file, $bundleName, $extension, $domain, $locale)
    {
        $lines = [];
        $content = file_get_contents($file->getPathname());
        if (in_array($extension, ['yaml', 'yml'])) {
            $lines = Yaml::parse($content);
        } else if ($extension === 'xlf') {
            $dom = XmlUtils::loadFile($file->getPathname());
            $xliffVersion = $this->getVersionNumber($dom);
            if ('1.2' === $xliffVersion) {
                $lines = $this->extractXliff1($dom);
            } else if ('2.0' === $xliffVersion) {
                $lines = $this->extractXliff2($dom);
            }
        }

        if (false === empty($lines)) {
            $this->loadTranslationFromArray($lines, $bundleName, $domain, $locale);
        }
    }

    /**
     * Load translation file.
     *
     * @param array  $lines
     * @param string $bundleName
     * @param string $domain
     * @param string $locale
     * @param string $prefix
     */
    protected function loadTranslationFromArray($lines, $bundleName, $domain, $locale, $prefix = '')
    {
        foreach ($lines as $key => $value) {
            $fullKey = ('' != $prefix ? $prefix.'.' : '').$key;
            if (is_array($value)) {
                $this->loadTranslationFromArray($value, $bundleName, $domain, $locale, $fullKey);
            } else {
                $this->translations[$bundleName][$domain][$fullKey][$locale] = $value;
            }
        }
    }

    /**
     * @param array $locales
     * @param array $domains
     *
     * @return string
     */
    protected function getFileNamePattern(array $locales, array $domains)
    {
        static $fileFormats = [
            'yml',
            'yaml',
            'xlf'
        ];
        if (count($domains) > 1) {
            $regex = sprintf('/((%s)\.(%s)\.(%s))/', implode('|', $domains), implode('|', $locales), implode('|', $fileFormats));
        } elseif ('all' == $domains[0]) {
            $regex = sprintf('/(.*\.(%s)\.(%s))/', implode('|', $locales), implode('|', $fileFormats));
        } else {
            $regex = sprintf('/'.$domains[0].'(.*\.(%s)\.(%s))/', implode('|', $locales), implode('|', $fileFormats));
        }

        return $regex;
    }

    /**
     * Get translations array.
     *
     * @return array
     */
    public function getTranslations()
    {
        return $this->translations;
    }
}
