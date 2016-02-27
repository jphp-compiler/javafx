<?php
namespace ide\project\behaviours;

use ide\bundle\AbstractBundle;
use ide\bundle\AbstractJarBundle;
use ide\editors\ProjectEditor;
use ide\project\AbstractProjectBehaviour;
use ide\project\Project;
use ide\utils\FileUtils;
use ide\utils\PhpParser;
use php\gui\layout\UXHBox;
use php\gui\layout\UXVBox;
use php\gui\UXButton;
use php\gui\UXCheckbox;
use php\gui\UXLabel;
use php\gui\UXNode;
use php\lib\fs;
use php\lib\str;
use php\util\Configuration;

/**
 * Class BundleProjectBehaviour
 * @package ide\project\behaviours
 */
class BundleProjectBehaviour extends AbstractProjectBehaviour
{
    const CONFIG_BUNDLE_KEY_USE_IMPORTS = 'useImports';

    /**
     * @var UXNode
     */
    protected $uiSettings;

    /**
     * @var UXHBox
     */
    protected $uiPackages;

    /**
     * @var AbstractBundle[]
     */
    protected $bundles = [];

    /**
     * @var Configuration[]
     */
    protected $bundleConfigs = [];

    /**
     * @var UXCheckbox
     */
    protected $uiUseImportCheckbox;

    /**
     * @return int
     */
    public function getPriority()
    {
        return self::PRIORITY_SYSTEM;
    }

    /**
     * ...
     */
    public function inject()
    {
        $this->project->on('save', [$this, 'doSave']);
        $this->project->on('preCompile', [$this, 'doPreCompile']);
        $this->project->on('makeSettings', [$this, 'doMakeSettings']);
        $this->project->on('updateSettings', [$this, 'doUpdateSettings']);
    }

    public function doSave()
    {
        foreach ($this->bundles as $env => $group) {
            /** @var AbstractBundle $bundle */
            foreach ($group as $bundle) {
                $type = get_class($bundle);
                $type = str::replace($type, '\\', '.');

                $config = $this->project->getIdeConfig("bundles/$type.conf");
                $config->set('env', $env);

                $bundle->onSave($this->project, $config);
            }
        }

        if ($this->uiSettings) {
            $this->setIdeConfigValue(self::CONFIG_BUNDLE_KEY_USE_IMPORTS, $this->uiUseImportCheckbox->selected);
        }
    }

    public function doLoad()
    {
        $files = $this->project->getIdeFile("bundles/")->findFiles();

        foreach ($files as $file) {
            if (fs::ext($file) == '.conf' && fs::isFile($file)) {
                $config = $this->project->getIdeConfig("bundles/" . fs::name($file));

                $class = str::replace(fs::nameNoExt($file), '.', '\\');

                if (class_exists($class)) {
                    $bundle = new $class();

                    if ($bundle instanceof AbstractBundle) {
                        $this->bundleConfigs[get_class($bundle)] = $config;

                        $bundle->onLoad($this->project, $config);
                        $this->addBundle($config->get('env') ?: Project::ENV_ALL, $bundle);
                    }
                }
            }
        }
    }

    protected function doPreCompileUseImports($env, callable $log = null)
    {
        if ($this->getIdeConfigValue(self::CONFIG_BUNDLE_KEY_USE_IMPORTS, true)) {
            $withSourceMap = Project::ENV_DEV == $env;
            $imports = [];

            $allBundles = $this->fetchAllBundles($env);

            foreach ($allBundles as $bundle) {
                foreach ($bundle->getUseImports() as $useImport) {
                    $imports[$useImport] = [$useImport];
                }
            }

            if ($imports) {
                FileUtils::scan($this->project->getFile('src/app'), function ($filename) use ($imports, $log, $withSourceMap) {
                    if (str::endsWith($filename, '.php')) {
                        $phpParser = PhpParser::ofFile($filename, $withSourceMap);

                        $phpParser->addUseImports($imports);

                        if ($log) {
                            $filename = fs::normalize($filename);
                            $file = $this->project->getAbsoluteFile($filename);

                            if (!$file->exists()) {
                                return;
                            }

                            $log(":import use '{$file->getRelativePath()}'");
                        }

                        $phpParser->saveContent($filename, $withSourceMap);
                    }
                });
            }
        }
    }

    public function doPreCompile($env, callable $log = null)
    {
        FileUtils::scan($this->project->getFile('src/'), function ($filename) {
            if (str::endsWith($filename, '.php.source')) {
                FileUtils::copyFile($filename, FileUtils::stripExtension($filename)); // rewrite from origin.
            }
        });

        $gradle = GradleProjectBehaviour::get();
        $allBundles = $this->fetchAllBundles($env);

        if ($gradle) {
            $gradle->addJcenterRepository();
            $gradle->addMavenCentralRepository();
            $gradle->addMavenLocalRepository();
            $gradle->addLocalLibRepository();


            foreach ($allBundles as $bundle) {
                if ($log) {
                    $log(':apply-bundle "' . $bundle->getName() . '"');
                }

                $bundle->applyForGradle($gradle);
            }

            foreach ($allBundles as $bundle) {
                $bundle->onPreCompile($this->project, $env, $log);
            }
        }

        $this->doPreCompileUseImports($env, $log);
    }

    /**
     * @param $env
     * @return \ide\bundle\AbstractBundle[]
     */
    public function fetchAllBundles($env)
    {
        $result = [];

        $fetchDependencies = function ($dependencies) use ($env, &$result, &$fetchDependencies) {
            foreach ($dependencies as $dep) {
                if (!$result[$dep]) {
                    $result[$dep] = $one = $this->fetchBundle($env, $dep);

                    $fetchDependencies($one->getDependencies());
                }
            }
        };

        $groups = [(array)$this->bundles[$env]];

        if ($env != Project::ENV_ALL) {
            $groups[] = (array)$this->bundles[Project::ENV_ALL];
        }

        /** @var AbstractBundle $bundle */
        foreach ($groups as $group) {
            foreach ($group as $bundle) {
                $fetchDependencies($bundle->getDependencies());

                $type = get_class($bundle);

                if (!$result[$type]) {
                    $result[$type] = $bundle;
                }
            }
        }

        return $result;
    }

    /**
     * @param $env
     * @param $class
     * @return AbstractBundle
     */
    public function fetchBundle($env, $class)
    {
        if ($bundle = $this->bundles[$env][$class]) {
            return $bundle;
        }

        if ($bundle = $this->bundles[Project::ENV_ALL][$class]) {
            return $bundle;
        }

        return $bundle = new $class();
    }

    /**
     * @param string $env
     * @param string $class
     */
    public function addBundle($env, $class)
    {
        if (!$this->bundles[$env][$class]) {
            unset($this->bundles[Project::ENV_ALL][$class]);

            $this->bundles[$env][$class] = new $class();
        }
    }

    /**
     * @param $name
     * @return null
     */
    public function findClassByShortName($name)
    {
        foreach ($this->fetchAllBundles(Project::ENV_ALL) as $one) {
            if ($one instanceof AbstractJarBundle) {
                foreach ($one->getUseImports() as $import) {
                    $_name = fs::name($import);

                    if (str::equalsIgnoreCase($name, $_name)) {
                        return $import;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param AbstractBundle $bundle
     * @return Configuration
     */
    public function getBundleConfig(AbstractBundle $bundle)
    {
        return $this->bundleConfigs[get_class($bundle)];
    }

    public function doUpdateSettings(ProjectEditor $editor = null)
    {
        if ($this->uiSettings) {
            $this->uiPackages->children->clear();

            foreach ($this->bundles as $env => $group) {
                /** @var AbstractBundle $bundle */
                foreach ($group as $bundle) {
                    $uiItem = new UXButton($bundle->getName() . " [$env]");
                    $uiItem->tooltipText = $bundle->getDescription();

                    $this->uiPackages->add($uiItem);
                }
            }

            $addButton = new UXButton();
            $addButton->graphic = ico('plus16');
            $addButton->on('action', function () {
                alert('В разработке ...');
            });
            $this->uiPackages->add($addButton);

            $this->uiUseImportCheckbox->selected = $this->getIdeConfigValue(self::CONFIG_BUNDLE_KEY_USE_IMPORTS, true);
        }
    }

    public function doMakeSettings(ProjectEditor $editor)
    {
        $title = new UXLabel('Пакеты:');
        $title->font = $title->font->withBold();

        $packages = new UXHBox();
        $packages->spacing = 5;
        $this->uiPackages = $packages;

        $this->uiUseImportCheckbox = $useImportCheckbox = new UXCheckbox("Добавлять use импорты классов");
        $useImportCheckbox->tooltipText = 'Добавлять во все исходники подключение классов через use из всех пакетов';

        $ui = new UXVBox([$title, $packages, $useImportCheckbox]);
        $ui->spacing = 5;

        $this->uiSettings = $ui;

        $editor->addSettingsPane($ui);
    }
}