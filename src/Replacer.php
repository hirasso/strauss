<?php

namespace CoenJacobs\Mozart;

use CoenJacobs\Mozart\Composer\Autoload\Autoloader;
use CoenJacobs\Mozart\Composer\Autoload\Classmap;
use CoenJacobs\Mozart\Composer\Autoload\NamespaceAutoloader;
use CoenJacobs\Mozart\Composer\MozartConfig;
use CoenJacobs\Mozart\Composer\ComposerPackageConfig;
use CoenJacobs\Mozart\Replace\ClassmapReplacer;
use CoenJacobs\Mozart\Replace\NamespaceReplacer;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

class Replacer
{
    /** @var string */
    protected $workingDir;

    /** @var string */
    protected $targetDir;

    /** @var MozartConfig */
    protected $config;

    /** @var array */
    protected $replacedClasses = [];

    /** @var Filesystem */
    protected $filesystem;

    public function __construct($workingDir, MozartConfig $config)
    {
        $this->workingDir = $workingDir;
        $this->targetDir = $config->getDepDirectory();
        $this->config = $config;

        $this->filesystem = new Filesystem(new Local($this->workingDir));
    }

    public function replacePackage(ComposerPackageConfig $package): void
    {
        foreach ($package->getAutoloaders() as $autoloader) {
            $this->replacePackageByAutoloader($package, $autoloader);
        }
    }

    /**
     * @param $targetFile
     * @param $autoloader
     *
     * @return void
     */
    public function replaceInFile($targetFile, Autoloader $autoloader): void
    {
        $targetFile = str_replace($this->workingDir, '', $targetFile);
        try {
            $contents = $this->filesystem->read($targetFile);
        } catch (FileNotFoundException $e) {
            return;
        }

        if (empty($contents) || false === $contents) {
            return;
        }

        if ($autoloader instanceof NamespaceAutoloader) {
            $replacer = new NamespaceReplacer();
            $replacer->setDepNamespace($this->config->getDepNamespace());
        } else {
            $replacer = new ClassmapReplacer();
            $replacer->setClassmapPrefix($this->config->getClassmapPrefix());
        }

        $replacer->setAutoloader($autoloader);
        $contents = $replacer->replace($contents);

        if ($replacer instanceof ClassmapReplacer) {
            $this->replacedClasses = array_merge($this->replacedClasses, $replacer->getReplacedClasses());
        }

        $this->filesystem->put($targetFile, $contents);
    }

    /**
     * @param ComposerPackageConfig $package
     * @param $autoloader
     *
     * @return void
     */
    public function replacePackageByAutoloader(ComposerPackageConfig $package, Composer\Autoload\Autoloader $autoloader): void
    {
        if ($autoloader instanceof NamespaceAutoloader) {
            $source_path = str_replace(
                '\\',
                DIRECTORY_SEPARATOR,
                $this->workingDir . $this->targetDir . $autoloader->getNamespace()
            );
            $this->replaceInDirectory($autoloader, $source_path);
        } elseif ($autoloader instanceof Classmap) {
            $finder = new Finder();
            $source_path = $this->workingDir . $this->config->getClassmapDirectory() . DIRECTORY_SEPARATOR
                           . $package->getName();
            $finder->files()->in($source_path);

            foreach ($finder as $foundFile) {
                $targetFile = $foundFile->getRealPath();

                if ('.php' == substr($targetFile, -4, 4)) {
                    $this->replaceInFile($targetFile, $autoloader);
                }
            }
        }
    }

    /**
     * @param $autoloader
     * @param $directory
     *
     * @return void
     */
    public function replaceParentClassesInDirectory(string $directory): void
    {
        if (count($this->replacedClasses)===0) {
            return;
        }

        $directory = trim($directory, '\\/'.DIRECTORY_SEPARATOR);
        $finder = new Finder();
        $finder->files()->in($directory);

        $replacedClasses = $this->replacedClasses;

        foreach ($finder as $file) {
            $targetFile = $file->getPathName();

            if ('.php' == substr($targetFile, -4, 4)) {
                try {
                    $contents = $this->filesystem->read($targetFile);
                } catch (FileNotFoundException $e) {
                    continue;
                }

                if (empty($contents) || false === $contents) {
                    continue;
                }

                foreach ($replacedClasses as $original => $replacement) {
                    $contents = preg_replace_callback(
                        '/(.*)([^a-zA-Z0-9_\x7f-\xff])'. $original . '([^a-zA-Z0-9_\x7f-\xff])/U',
                        function ($matches) use ($replacement) {
                            if (preg_match('/(include|require)/', $matches[0], $output_array)) {
                                return $matches[0];
                            }
                            return $matches[1] . $matches[2] . $replacement . $matches[3];
                        },
                        $contents
                    );
                }

                $this->filesystem->put($targetFile, $contents);
            }
        }
    }

    /**
     * @param $autoloader
     * @param $directory
     *
     * @return void
     */
    public function replaceInDirectory(NamespaceAutoloader $autoloader, string $directory): void
    {
        $finder = new Finder();
        try {
            $finder->files()->in($directory);
        } catch (DirectoryNotFoundException $e) {
            return;
        }

        foreach ($finder as $file) {
            $targetFile = $file->getPathName();

            if ('.php' == substr($targetFile, -4, 4)) {
                $this->replaceInFile($targetFile, $autoloader);
            }
        }
    }

    /**
     * Replace everything in parent package, based on the dependency package.
     * This is done to ensure that package A (which requires package B), is also
     * updated with the replacements being made in package B.
     *
     * @param ComposerPackageConfig $package
     * @param ComposerPackageConfig $parent
     *
     * @return void
     */
    public function replaceParentPackage(ComposerPackageConfig $package, ComposerPackageConfig $parent): void
    {
        foreach ($parent->getAutoloaders() as $parentAutoloader) {
            foreach ($package->getAutoloaders() as $autoloader) {
                if ($parentAutoloader instanceof NamespaceAutoloader) {
                    $namespace = str_replace('\\', DIRECTORY_SEPARATOR, $parentAutoloader->getNamespace());
                    $directory = $this->workingDir . $this->config->getDepDirectory() . $namespace
                                 . DIRECTORY_SEPARATOR;

                    if ($autoloader instanceof NamespaceAutoloader) {
                        $this->replaceInDirectory($autoloader, $directory);
                    } else {
                        $directory = str_replace($this->workingDir, '', $directory);
                        $this->replaceParentClassesInDirectory($directory);
                    }
                } else {
                    $directory = $this->workingDir . $this->config->getClassmapDirectory() . $parent->getName();

                    if ($autoloader instanceof NamespaceAutoloader) {
                        $this->replaceInDirectory($autoloader, $directory);
                    } else {
                        $directory = str_replace($this->workingDir, '', $directory);
                        $this->replaceParentClassesInDirectory($directory);
                    }
                }
            }
        }
    }
}
